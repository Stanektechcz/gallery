<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Existuje ta tabulka? Zeptej se jednou za požadavek, ne dvacetkrát.
 *
 * Aplikace roste po částech a skoro každý poskytovatel obsahu i převodník
 * stavu se nejdřív ptá, jestli tabulka a sloupec vůbec existují — jinak by
 * galerie spadla u dvojice, která ještě nemá spuštěnou poslední migraci.
 * Ta opatrnost je správná, jen se opakuje: jedno načtení galerie se na
 * `transactions` ptalo osmnáctkrát, na `journal_entries` sedmnáctkrát
 * a dohromady vyšlo 240 z 657 dotazů jen na schéma. Na SQLite je to levné,
 * na produkční MySQL je každý takový dotaz cesta do `information_schema`.
 *
 * Schéma se během požadavku nemění (migrace běží při nasazení), takže stačí
 * odpověď schovat. Paměť se čistí při každém startu aplikace — v testech tím
 * pádem mezi testy, aby si jeden test nenesl schéma druhého.
 */
final class Tabulky
{
    /** @var array<string, bool> */
    private static array $tabulky = [];

    /** @var array<string, bool> */
    private static array $sloupce = [];

    public static function je(string $tabulka): bool
    {
        return self::$tabulky[$tabulka] ??= Schema::hasTable($tabulka);
    }

    public static function sloupec(string $tabulka, string $sloupec): bool
    {
        $klic = $tabulka.'.'.$sloupec;

        // Bez tabulky se na sloupec neptáme — u MySQL by to byl druhý zbytečný dotaz.
        return self::$sloupce[$klic] ??= self::je($tabulka) && Schema::hasColumn($tabulka, $sloupec);
    }

    /** Zapomenout, co víme — po migraci a při startu aplikace. */
    public static function zapomen(): void
    {
        self::$tabulky = [];
        self::$sloupce = [];
    }
}
