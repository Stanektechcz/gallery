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
 */
final class Vejde
{
    /** Výchozí délka `string()` sloupce v Laravelu. */
    public const SLOUPEC = 255;

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
}
