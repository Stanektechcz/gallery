<?php

namespace App\Support;

/**
 * Text, který se vejde do sloupce.
 *
 * Převodníky stavu (`app/Services/Provoz/*VeStavu.php`) berou texty rovnou
 * z prohlížeče a zapisují je do sloupců, které mají v MySQL 255 znaků.
 * Vývojová SQLite delší řetězec mlčky přijme, MySQL ve striktním režimu ne —
 * vyhodí výjimku, a protože se celý `PATCH /api/state` zapisuje v jedné
 * transakci, spadne s ním **všechno ostatní z toho zápisu**. Stačilo by, aby
 * někdo vlepil do názvu domácí práce odstavec z e-mailu: aplikace by od té
 * chvíle hlásila chybu při každém uložení a dvojice by přišla i o to, co
 * s tím nesouvisí.
 *
 * Ořezává se proto tady, na jednom místě, a ořezává se **po znacích**
 * (`mb_substr`), ne po bajtech — jinak by se diakritika rozsekla vejpůl
 * a v databázi by skončil neplatný UTF-8.
 *
 * Stejná past je u čísel a dat (`cislo()`, `den()`): záporné číslo do
 * `unsigned` sloupce nebo 30. únor shodí zápis na MySQL úplně stejně.
 */
final class Vejde
{
    /** Výchozí délka `string()` sloupce v Laravelu. */
    public const SLOUPEC = 255;

    /**
     * Šířka sloupce `client_id`, tedy identifikátoru z prohlížeče.
     *
     * Je stejná ve všech jedenácti tabulkách, ale ořezávalo se na 80 — o
     * šestnáct znaků víc, než se tam vejde. Klient si identifikátory skládá
     * sám (`'c-' + Date.now()` a podobně), takže délku nikdo nehlídá.
     */
    public const KLIENT = 64;

    /** Text pro sloupec dané délky; prázdný řetězec zůstane prázdný. */
    public static function do(mixed $text, int $max = self::SLOUPEC): string
    {
        $text = is_scalar($text) ? (string) $text : '';

        return mb_substr(trim($text), 0, $max);
    }

    /** Totéž, ale prázdný text je `null` — pro nullable sloupce. */
    public static function neboNic(mixed $text, int $max = self::SLOUPEC): ?string
    {
        $text = self::do($text, $max);

        return $text === '' ? null : $text;
    }

    /**
     * Patch s identifikátory z prohlížeče zkrácenými na šířku `client_id`.
     *
     * Převodníky ukládají `client_id` ořezaný na 64 znaků, ale řádky hledají
     * podle identifikátoru tak, jak přišel. Delší identifikátor se proto při
     * dalším zápisu nenašel, převodník řádek založil znovu — a narazil na
     * unikátní klíč `(gallery_space_id, client_id)`. PATCH spadl na 500
     * a prohlížeč ho zkoušel znovu donekonečna. Zkrátit se musí i rozdíly
     * (`__odebrane`, `__zmenene`), jinak by se takový řádek nedal změnit
     * ani odebrat. Uuid ze serveru (36 znaků) zůstane, jak je.
     *
     * @param  array<string, mixed>  $patch
     * @param  list<string>  $klice  seznamy, jejichž řádky mají `id`
     * @return array<string, mixed>
     */
    public static function identifikatory(array $patch, array $klice): array
    {
        $zkrat = fn (mixed $id) => is_scalar($id) ? self::do($id, self::KLIENT) : $id;

        foreach ($klice as $klic) {
            if (is_array($patch[$klic] ?? null)) {
                $patch[$klic] = array_map(
                    fn (mixed $r) => is_array($r) && isset($r['id']) && is_scalar($r['id'])
                        ? ['id' => $zkrat($r['id'])] + $r
                        : $r,
                    $patch[$klic],
                );
            }

            foreach (['__odebrane', '__zmenene'] as $rozdil) {
                if (is_array($patch[$rozdil][$klic] ?? null)) {
                    $patch[$rozdil][$klic] = array_map($zkrat, $patch[$rozdil][$klic]);
                }
            }
        }

        return $patch;
    }

    /** Horní mez `unsignedTinyInteger` v MySQL. */
    public const TINY = 255;

    /** Horní mez `unsignedSmallInteger` v MySQL. */
    public const SMALL = 65535;

    /** Horní mez `unsignedInteger` v MySQL. */
    public const INT = 4294967295;

    /**
     * Celé číslo, které se vejde do sloupce.
     *
     * Totéž co u textu, jen s čísly: MySQL ve striktním režimu odmítne
     * zápis 10¹² do `unsignedInteger` i −1 do čehokoli `unsigned` a s ním
     * spadne celý PATCH. SQLite uloží cokoli, takže to vývoj neukáže.
     *
     * Ořezává se v `float`, ne v `int`: přetypování obřího čísla (`1e20`)
     * na `int` přeteče ještě dřív, než by se dalo porovnat. Co není číslo
     * (text, pole), je `$min` — stejně jako dřív `(int)`, které z nesmyslu
     * udělalo nulu.
     */
    public static function cislo(mixed $hodnota, int $min, int $max): int
    {
        $cislo = is_numeric($hodnota) || is_bool($hodnota) ? (float) $hodnota : (float) $min;

        if (is_nan($cislo)) {
            return $min;
        }

        return (int) max((float) $min, min((float) $max, $cislo));
    }

    /** Totéž pro nullable sloupec: chybějící nebo prázdná hodnota je `null`. */
    public static function cisloNeboNic(mixed $hodnota, int $min, int $max): ?int
    {
        return $hodnota === null || $hodnota === '' ? null : self::cislo($hodnota, $min, $max);
    }

    /**
     * Datum `Y-m-d`, které opravdu existuje, jinak `null`.
     *
     * Převodníky hlídaly jen tvar (`\d{4}-\d{2}-\d{2}`), takže prošel i
     * 45. den třináctého měsíce. SQLite ho uloží jako text, MySQL zápis
     * odmítne a Carbon `parse()` na něm rovnou vyhodí výjimku.
     */
    public static function den(mixed $text): ?string
    {
        $text = is_scalar($text) ? trim((string) $text) : '';

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $shoda)) {
            return null;
        }

        return checkdate((int) $shoda[2], (int) $shoda[3], (int) $shoda[1]) ? $text : null;
    }
}
