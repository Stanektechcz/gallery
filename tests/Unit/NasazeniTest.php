<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nasazení zazálohuje databázi dřív, než sáhne na schéma.
 *
 * `deploy.sh` o migraci sám píše, že je to „jediný krok, který nejde vzít
 * zpět" — a před ní žádná záloha neběžela (do kola 2ag žádná ani nebyla).
 * Nepovedená migrace nad provozní databází pak neměla cestu zpátky.
 */
class NasazeniTest extends TestCase
{
    private static function skript(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/deploy.sh');
    }

    public function test_pred_migraci_bezi_zaloha(): void
    {
        $skript = self::skript();

        $zaloha = strpos($skript, 'artisan gallery:zaloha');
        $migrace = strpos($skript, 'artisan migrate --force');

        $this->assertNotFalse($migrace, 'deploy.sh migrace nespouští — test by hlídal naprázdno.');
        $this->assertNotFalse($zaloha, 'deploy.sh před migrací nezálohuje databázi.');
        $this->assertLessThan($migrace, $zaloha, 'Záloha musí běžet před migrací, ne po ní.');
    }

    /** Nepovedená záloha nasazení zastaví — nesmí se spolknout. */
    public function test_nepovedena_zaloha_nasazeni_zastavi(): void
    {
        $skript = self::skript();

        $this->assertStringContainsString('set -euo pipefail', $skript);
        $this->assertDoesNotMatchRegularExpression('/artisan gallery:zaloha[^\n]*\|\|\s*true/', $skript,
            'Záloha s `|| true` by selhala potichu a migrace by běžela dál bez ní.');
    }
}
