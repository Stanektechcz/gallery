<?php

namespace Tests;

use App\Support\Cas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
     * Hodnota je prázdná až na dno: seznamy a mapy bez položek, texty prázdné,
     * čísla nulová.
     *
     * Obsah prototypu chodí i pro nepoužitý modul — jako prázdné kolekce ve
     * správném tvaru, aby na obrazovce nezůstala ukázka. Test tedy neověřuje,
     * že klíč chybí, ale že v něm nic není (a hlavně nic cizího).
     */
    protected function assertPrazdne(mixed $hodnota, string $cesta = 'data'): void
    {
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
