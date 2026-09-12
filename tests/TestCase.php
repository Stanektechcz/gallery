<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
