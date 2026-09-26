<?php

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Napodobená chyba databáze u jedné tabulky — bez změny schématu.
 *
 * Dřív se chyba napodobovala zahozením tabulky (`Schema::drop`) a jejím
 * pozdějším založením znovu. Na SQLite to v transakci `RefreshDatabase`
 * projde; na MySQL zahození transakci potvrdí, zbytek testu běží bez ní
 * a tabulka se vrátí bez cizích klíčů — i pro všechny další testy.
 *
 * Tady každý dotaz, který tabulku jmenuje, spadne na `QueryException` ještě
 * před odesláním — stejně jako dotaz na tabulku, která zrovna nejde. Čtení
 * i zápis, na SQLite (`"tabulka"`) i MySQL (`` `tabulka` ``). Schéma zůstává,
 * transakce testu taky.
 */
trait SelhavajiciTabulka
{
    /** @var array<string, true> */
    private array $rozbiteTabulky = [];

    private bool $hlidacTabulekZaregistrovan = false;

    protected function rozbijTabulku(string $tabulka): void
    {
        $this->rozbiteTabulky[$tabulka] = true;

        if ($this->hlidacTabulekZaregistrovan) {
            return;
        }

        $this->hlidacTabulekZaregistrovan = true;

        DB::connection()->beforeExecuting(function (string $sql, array $vazby, Connection $spojeni): void {
            foreach (array_keys($this->rozbiteTabulky) as $rozbita) {
                if (preg_match('/[`"]'.preg_quote($rozbita, '/').'[`"]/', $sql) === 1) {
                    throw new QueryException(
                        $spojeni->getName(),
                        $sql,
                        $vazby,
                        new \PDOException("Napodobená chyba databáze: tabulka {$rozbita} nejde."),
                    );
                }
            }
        });
    }

    protected function opravTabulku(string $tabulka): void
    {
        unset($this->rozbiteTabulky[$tabulka]);
    }
}
