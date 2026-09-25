<?php

namespace Tests;

use App\Models\User;
use App\Support\Cas;
use App\Support\Trezor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Volitelně pustí test v zadaném okamžiku z proměnné `TESTY_CAS`.
     *
     * Chyby „kterým dnem je dnes" se ukážou jen mezi pražskou půlnocí a druhou
     * (v zimě první) hodinou ranní, kdy má dvojice už nové datum a UTC ještě
     * staré. Aby šly najít kdykoli, ne jen v noci:
     *
     *     TESTY_CAS="2026-09-25 00:30 Europe/Prague" vendor/bin/phpunit
     *     TESTY_CAS="2026-01-15 00:30 Europe/Prague" vendor/bin/phpunit
     *
     * Hodiny se jen posunou a běží dál — nezmrazí se. Zmrazený čas by sám
     * rozbil testy, které čekají, že „později" je opravdu později (pořadí
     * podle `created_at`, zneplatnění klíče), a noční chyby by se v nich
     * ztratily. Test, který si čas nastavuje sám, ho svým `travelTo()`
     * přebije. Bez proměnné se nic nemění.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $testovaciCas = getenv('TESTY_CAS');

        if (! is_string($testovaciCas) || trim($testovaciCas) === '') {
            return;
        }

        $posunVSekundach = CarbonImmutable::parse(trim($testovaciCas))->getTimestamp() - time();

        // Z hodin systému, ne z parametru, který Carbon uzávěru předává: ten
        // pro `now('Europe/Prague')` nese čas UTC označený pražským pásmem,
        // takže by se okamžik posunul ještě jednou o dvě hodiny. Přes holý
        // `DateTimeImmutable`: `createFromTimestamp()` by se uvnitř uzávěru
        // ptal na „teď" a volal sám sebe, a bez `instance()` by `create()`
        // dostal objekt, který neumí `rawFormat()`.
        CarbonImmutable::setTestNow(
            static fn () => CarbonImmutable::instance(
                new \DateTimeImmutable('@'.sprintf('%.6F', microtime(true) + $posunVSekundach)),
            ),
        );
    }

    /**
     * Dnešek dvojice — tím, co aplikace považuje za „dnes".
     *
     * Aplikace běží v UTC a obsah pro obrazovky počítá dny podle pásma
     * dvojice (`app.display_timezone`, Europe/Prague). Mezi pražskou půlnocí
     * a druhou hodinou ranní jsou to **dvě různá data**, takže fixtura
     * postavená na `now()` v tom okně měřila o den vedle — a celá řada testů
     * procházela jen proto, že se pouštěly přes den.
     *
     * Kde test znamená „dnešek dvojice", patří sem `dnes()`; kde znamená
     * skutečný okamžik (uloženo v UTC), zůstává `now()`.
     */
    protected function dnes(): CarbonImmutable
    {
        return Cas::dnes();
    }

    /** Teď na hodinách dvojice — pro fixtury, které potřebují i čas. */
    protected function ted(): CarbonImmutable
    {
        return Cas::ted();
    }

    /**
     * Sezení s trezorem odemčeným pro `$kdo` — do `withSession()`.
     *
     * Odemčení patří člověku, ne prohlížeči (`App\Support\Trezor`): samotný
     * čas v sezení trezor neotevře, musí u něj být i kdo ho odemkl.
     *
     * @return array<string, int>
     */
    protected function odemcenyTrezor(User $kdo, int $minut = 5): array
    {
        return [
            Trezor::DO => now()->addMinutes($minut)->timestamp,
            Trezor::KDO => (int) $kdo->id,
        ];
    }

    /**
     * Hodnota je prázdná až na dno: seznamy a mapy bez položek, texty prázdné,
     * čísla nulová.
     *
     * Obsah prototypu chodí i pro nepoužitý modul — jako prázdné kolekce ve
     * správném tvaru, aby na obrazovce nezůstala ukázka. Test tedy neověřuje,
     * že klíč chybí, ale že v něm nic není (a hlavně nic cizího).
     */
    protected function assertPrazdne(mixed $hodnota, string $cesta = 'data'): void
    {
        // `stdClass` je v poskytovatelích tvar „mapa podle identifikátorů".
        // Z HTTP chodí jako pole, ale při přímém volání `prazdne()` zůstává
        // objektem — bez tohohle řádku by prázdná mapa spadla na skalár.
        if ($hodnota instanceof \stdClass) {
            $hodnota = (array) $hodnota;
        }

        if (is_array($hodnota)) {
            foreach ($hodnota as $klic => $vnitrek) {
                $this->assertPrazdne($vnitrek, $cesta.'.'.$klic);
            }

            $this->addToAssertionCount(1);

            return;
        }

        $this->assertTrue(
            $hodnota === null || $hodnota === '' || $hodnota === 0 || $hodnota === 0.0 || $hodnota === false,
            "„{$cesta}“ není prázdné: ".json_encode($hodnota, JSON_UNESCAPED_UNICODE),
        );
    }
}
