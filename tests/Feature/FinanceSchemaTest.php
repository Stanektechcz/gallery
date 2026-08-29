<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sloupce musí unést to, co validace pouští dovnitř.
 *
 * Tohle je test na třídu chyb, kterou zdejší sada z principu nechytá: vývoj běží na
 * SQLite, která délku textu nehlídá, produkce na MySQL, která ano. Sloupec `country`
 * měl dva znaky, validace pouštěla osmdesát a „Německo" se dalo uložit půl roku —
 * až do prvního nasazení, kde spadlo všechno, co mělo zemi delší než dva znaky.
 *
 * Délky se čtou z **migrací**, ne z databáze. SQLite je do schématu vůbec neuloží:
 * `varchar(2)` i `varchar(2000)` z ní vyjdou jako prosté `varchar`, takže proti
 * běžící testovací databázi se tahle chyba zjistit nedá. V migraci je pravda o tom,
 * co vznikne na produkci.
 */
class FinanceSchemaTest extends TestCase
{
    /**
     * Kde do sloupce zapisuje formulář a s jakým stropem.
     *
     * Ručně sepsané schválně: automat by musel rozumět tomu, které pravidlo patří
     * ke kterému sloupci, a spletl by se právě u těch, kde se jméno pole liší od
     * jména sloupce — tedy tam, kde chyby vznikají.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function textovaPole(): array
    {
        $pole = [
            ['finance_projects', 'name', 160],
            ['finance_projects', 'country', 80],
            ['finance_projects', 'city', 120],
            ['finance_projects', 'purpose', 500],
            ['transactions', 'description', 500],
            ['transactions', 'counterparty', 200],
            ['transactions', 'provider', 60],
            ['transactions', 'place', 120],
            ['transactions', 'exclusion_reason', 200],
            ['wallets', 'name', 160],
            ['finance_categories', 'name', 80],
            ['budgets', 'name', 160],
            ['finance_recurring', 'name', 120],
            ['finance_templates', 'name', 80],
            ['finance_settings', 'alert_thresholds', 40],
        ];

        return collect($pole)->mapWithKeys(fn (array $r) => ["{$r[0]}.{$r[1]}" => $r])->all();
    }

    #[DataProvider('textovaPole')]
    public function test_sloupec_unese_co_validace_pousti(string $tabulka, string $sloupec, int $max): void
    {
        $delka = $this->delkaZMigraci($tabulka, $sloupec);

        $this->assertNotNull($delka,
            "Sloupec {$tabulka}.{$sloupec} se v migracích nenašel. Buď se přejmenoval, nebo tenhle test zastaral.");

        $this->assertGreaterThanOrEqual($max, $delka,
            "Sloupec {$tabulka}.{$sloupec} pojme {$delka} znaků, ale validace jich pouští {$max}. "
                .'Na SQLite to projde, na MySQL spadne — přesně jako `country` u cesty.');
    }

    /**
     * Poslední délka, kterou sloupci daly migrace.
     *
     * Pozdější migrace přebíjí dřívější: `country` vznikl jako dvouznakový a teprve
     * pozdější `->change()` z něj udělal stoznakový. Kdyby se bralo první nalezení,
     * test by hlásil chybu, která je dávno opravená.
     *
     * `text()` a spol. délku nemají — vracejí neomezeno, tedy PHP_INT_MAX.
     */
    private function delkaZMigraci(string $tabulka, string $sloupec): ?int
    {
        $soubory = glob(database_path('migrations/*.php'));
        sort($soubory);

        $nalezeno = null;

        foreach ($soubory as $soubor) {
            $obsah = (string) file_get_contents($soubor);

            // Blok patřící tabulce: od `Schema::create('x'` nebo `Schema::table('x'`
            // po nejbližší další `Schema::`. Bez toho by se chytil stejnojmenný
            // sloupec z úplně jiné tabulky ve stejném souboru.
            $vzor = "/Schema::(?:create|table)\(\s*'".preg_quote($tabulka, '/')."'.*?(?=Schema::|\z)/s";

            if (! preg_match_all($vzor, $obsah, $bloky)) {
                continue;
            }

            foreach ($bloky[0] as $blok) {
                if (preg_match_all(
                    "/->(string|char)\(\s*'".preg_quote($sloupec, '/')."'\s*,\s*(\d+)/",
                    $blok, $shody, PREG_SET_ORDER,
                )) {
                    $nalezeno = (int) end($shody)[2];
                }

                // Sloupec bez délky (`string('x')` nebo `text('x')`) je neomezený,
                // respektive Laravelem výchozích 255 u `string`.
                if (preg_match("/->text\(\s*'".preg_quote($sloupec, '/')."'\s*\)/", $blok)) {
                    $nalezeno = PHP_INT_MAX;
                }

                if (preg_match("/->string\(\s*'".preg_quote($sloupec, '/')."'\s*\)/", $blok)) {
                    $nalezeno = 255;
                }
            }
        }

        return $nalezeno;
    }
}
