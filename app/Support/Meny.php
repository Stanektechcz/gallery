<?php

namespace App\Support;

use App\Models\FinanceSettings;
use App\Models\GallerySpace;

/**
 * Měny dvojice — která je hlavní, které se nabízejí a jak se píší.
 *
 * Dvojice rozhodla: „Hlavní měna je CZK, další měny jsou EUR, USD". Hlavní měna
 * je ta, ve které se ukazuje každý součet přes víc měn; výběry v aplikaci nabízejí
 * jen tyhle tři, koruna první. Import z banky a z rezervací ale dál bere jakýkoli
 * třípísmenný kód — výpis z Londýna v librách je pravda a zahodit ho by bylo horší
 * než ho neumět přepočítat.
 *
 * Znak měny se dřív psal na šesti místech (obsah obrazovek) a každá kopie uměla
 * něco jiného: jedna libru, druhá prázdnou měnu, třetí ani jedno. Tady je sjednocení
 * všech — co uměla aspoň jedna kopie, umí i tohle.
 */
final class Meny
{
    public const HLAVNI = 'CZK';

    /** Co nabízejí výběry měny. První je výchozí. */
    public const NABIZENE = ['CZK', 'EUR', 'USD'];

    /** Znaky, které obrazovky znají. Ostatní měny se píšou kódem — „CHF" je čitelné. */
    private const ZNAKY = [
        'CZK' => 'Kč',
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
    ];

    /**
     * Hlavní měna prostoru z předvoleb rozpočtu.
     *
     * Čte se bez `FinanceSettings::proProstor()`, protože ten řádek zakládá — a
     * obrazovka, která se jen dívá, nemá při každém otevření zapisovat. Bez předvoleb,
     * bez prostoru nebo s nesmyslem v databázi (cokoli, co není tři písmena) platí
     * koruna: přepočet do smyšlené měny by nevrátil nic, jen by tiše zmizel součet.
     *
     * Záměrně bez paměti mezi voláními. Je to jeden dotaz na jeden řádek, a statická
     * paměť by v testech i ve frontě přežila do dalšího prostoru.
     */
    public static function hlavni(GallerySpace|int|null $prostor): string
    {
        $id = $prostor instanceof GallerySpace ? (int) $prostor->getKey() : $prostor;

        if (! $id) {
            return self::HLAVNI;
        }

        $ulozena = FinanceSettings::query()->where('gallery_space_id', $id)->value('home_currency');

        return self::kod($ulozena) ?? self::HLAVNI;
    }

    /**
     * Kód měny velkými písmeny, nebo null, když to kód není.
     *
     * Kódem je cokoli ze tří písmen — i měna, kterou aplikace nenabízí. Import z banky
     * smí přinést libry nebo franky a ty se nesmí cestou ztratit.
     */
    public static function kod(mixed $mena): ?string
    {
        if (! is_string($mena)) {
            return null;
        }

        $kod = strtoupper(trim($mena));

        return preg_match('/^[A-Z]{3}$/', $kod) === 1 ? $kod : null;
    }

    /**
     * Znak měny pro obrazovku: Kč, €, $, £, jinak kód.
     *
     * Položka bez měny je koruna. Tak ji četly obrazovky cest, a starší zápisy
     * měnu opravdu nemají — do aplikace se dřív psalo jen v korunách.
     */
    public static function znak(?string $mena): string
    {
        $kod = strtoupper(trim((string) $mena));

        if ($kod === '') {
            return self::ZNAKY[self::HLAVNI];
        }

        return self::ZNAKY[$kod] ?? $kod;
    }

    /**
     * Částka po česku: „12 345 Kč", „1 234,50 €".
     *
     * Tisíce odděluje obyčejná mezera, desetiny čárka — stejně jako pět ze šesti
     * dosavadních kopií, a tak to čekají i testy obrazovek. Záporná částka dostane
     * obyčejné minus; typografické „−" nebo slovo „dlužíš" si píše volající, protože
     * každá obrazovka dluh ukazuje jinak.
     */
    public static function castka(float $castka, ?string $mena, int $desetin = 0): string
    {
        return number_format($castka, max(0, $desetin), ',', ' ').' '.self::znak($mena);
    }
}
