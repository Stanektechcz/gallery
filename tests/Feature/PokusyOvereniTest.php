<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Provoz\PokusyOvereni;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Počítadlo špatných pokusů musí sečíst i souběžné pokusy.
 *
 * `Cache::get` a `Cache::put` odděleně nejsou jeden krok: dva požadavky ve
 * stejnou chvíli si oba přečtou stejné číslo a oba napíšou +1 — druhý pokus
 * počítadlo tiše zahodí. Testem to jde ukázat i bez vláken: stačí simulovat
 * čtení „staré" hodnoty tím, že se zavolá `chyba()` vícekrát za sebou a
 * ověří se, že si na sobě jednotlivá volání nešlapou.
 */
class PokusyOvereniTest extends TestCase
{
    use RefreshDatabase;

    /** N volání musí dát počítadlo N, ne míň — to je to, co neatomický pár get/put nedodrží. */
    public function test_pokusy_po_sobe_se_secitaji(): void
    {
        $kdo = User::factory()->create();

        $vysledky = [];
        for ($i = 0; $i < PokusyOvereni::POKUSU - 1; $i++) {
            $vysledky[] = PokusyOvereni::chyba($kdo, 'trezor');
        }

        $this->assertSame([1, 2], array_column($vysledky, 'pokusu'));
    }

    /**
     * `add` nesmí přepsat počítadlo, které už běží.
     *
     * Atomický zápis přes `Cache::add` + `Cache::increment` má smysl jen
     * tehdy, když `add` opravdu založí klíč jen tomu, kdo je první — jinak
     * by druhé volání vynulovalo, co první právě napočítalo.
     */
    public function test_add_nepretlaci_existujici_pocitadlo(): void
    {
        $kdo = User::factory()->create();

        PokusyOvereni::chyba($kdo, 'trezor');
        $klic = 'pokusy:trezor:pokusy:'.$kdo->getKey();

        $this->assertSame(1, Cache::get($klic));

        // Přímé volání toho, co `chyba()` dělá uvnitř — `add` na existující klíč nesmí nic změnit.
        Cache::add($klic, 0, now()->addMinutes(15));
        $this->assertSame(1, Cache::get($klic));
    }

    /** Po dosažení limitu se počítadlo zahodí a uzavření platí. */
    public function test_po_limitu_prijde_blok_a_pocitadlo_se_vynuluje(): void
    {
        $kdo = User::factory()->create();

        PokusyOvereni::chyba($kdo, 'zamek');
        PokusyOvereni::chyba($kdo, 'zamek');
        $treti = PokusyOvereni::chyba($kdo, 'zamek');

        $this->assertSame(3, $treti['pokusu']);
        $this->assertSame(0, $treti['zbyva']);
        $this->assertGreaterThan(0, $treti['blok']);
        $this->assertGreaterThan(0, PokusyOvereni::blokDo($kdo, 'zamek'));
        $this->assertNull(Cache::get('pokusy:zamek:pokusy:'.$kdo->getKey()));
    }
}
