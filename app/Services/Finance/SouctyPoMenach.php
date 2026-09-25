<?php

namespace App\Services\Finance;

use App\Support\Meny;

/**
 * Společné kousky součtů po měnách.
 *
 * Pět obrazovek (Finance, FinanceRozbory, System, Tyden, Cesty, Dnes) potřebuje
 * pořád totéž: sjednotit klíč měny, sečíst řádky podle něj, poskládat součty do
 * jedné věty jako „1 000 Kč · 100 €" a napsat dovětek o tom, co se nevešlo do
 * přepočtu. Každá si to napsala zvlášť, a tak každá trochu jinak — tahle třída
 * drží jen to, co bylo doopravdy stejné, beze změny chování.
 *
 * Bez kurzů. Kde se sahá na `ExchangeRateService` (přepočet do jiné měny),
 * zůstává to u volajícího — ceny se tam liší natolik (celé/částečné selhání,
 * paměť kurzů), že by je jedna metoda jen zamlžila. Zdejší metody jsou čisté
 * funkce nad tím, co má volající už v ruce.
 */
final class SouctyPoMenach
{
    /**
     * Klíč měny pro součty: velkými písmeny, prázdná měna je `$vychozi`.
     *
     * Starší zápisy měnu nemají — psalo se jen v korunách. Nesmysl, který kódem
     * měny není, se nechává, jak je (velkými písmeny) — přepočet ho sám odmítne
     * a řekne to; tahle metoda o platnosti kódu nerozhoduje.
     */
    public static function klic(mixed $mena, string $vychozi): string
    {
        $kod = strtoupper(trim((string) $mena));

        return $kod === '' ? $vychozi : $kod;
    }

    /**
     * Řádky sečtené po měnách: `[měna => součet]`.
     *
     * `$mena` a `$castka` čtou z jednoho řádku to, co u něj sčítat — každá
     * obrazovka má jiná pole (`currency_from`, `mena`, `currency`…), a tak jinou
     * i případnou úpravu částky (např. `abs()` u výdajů). Bez zaokrouhlení a bez
     * filtru nulových položek — kde na tom volajícímu záleží (viz `zaokrouhli()`
     * a `odfiltrujNulove()`), udělá to sám, protože pořadí těch kroků se mezi
     * obrazovkami liší.
     *
     * @param  iterable<object>  $radky
     * @return array<string, float>
     */
    public static function secti(iterable $radky, callable $mena, callable $castka, string $vychozi): array
    {
        $soucty = [];

        foreach ($radky as $r) {
            $kod = self::klic($mena($r), $vychozi);
            $soucty[$kod] = ($soucty[$kod] ?? 0.0) + (float) $castka($r);
        }

        return $soucty;
    }

    /** Součty na haléře — stejně, jak je vrací i `ExchangeRateService::doHlavni()`. */
    public static function zaokrouhli(array $poMenach): array
    {
        return array_map(fn (float $castka) => round((float) $castka, 2), $poMenach);
    }

    /**
     * Zahodí měny, kde po zaokrouhlení zbyl jen šum, ne částka.
     *
     * @param  array<string, float>  $poMenach
     * @return array<string, float>
     */
    public static function odfiltrujNulove(array $poMenach, float $eps = 0.005): array
    {
        return array_filter($poMenach, fn (float $castka) => abs((float) $castka) >= $eps);
    }

    /**
     * Přesune klíč `$klic` na první místo, beze změny pořadí ostatních.
     *
     * Rozpis částek chce hlavní (nebo výchozí) měnu vpředu, ne kdekoli, kam ji
     * seřadil dotaz. `array_merge` nechá klíč na místě, kde se objevil poprvé.
     *
     * @param  array<string, float>  $soucty
     * @return array<string, float>
     */
    public static function presunNaZacatek(array $soucty, string $klic): array
    {
        return array_key_exists($klic, $soucty) ? array_merge([$klic => $soucty[$klic]], $soucty) : $soucty;
    }

    /**
     * Částky po měnách vedle sebe jako text, např. „1 000 Kč · 100 €" nebo „70 € + 500 Kč".
     *
     * `$format` píše jednu částku (obvykle `Meny::castka(...)`) — obrazovky se liší
     * v zaokrouhlení a v oddělovači tisíc (pevná mezera na Dnes a v Týdnu, ať se
     * číslo nezalomí), a to si nese volající, ne tahle metoda.
     *
     * @param  array<string, float>  $poMenach
     */
    public static function spoj(array $poMenach, string $oddelovac, callable $format): string
    {
        return implode($oddelovac, array_map(
            fn (string $mena, float $castka) => $format($castka, $mena),
            array_keys($poMenach),
            array_values($poMenach),
        ));
    }

    /**
     * Dovětek o částkách stranou: prázdný řetězec, když žádné nejsou.
     *
     * Pokrývá obě znění, která obrazovky používaly: „+40 € nezapočteno"
     * (`$predpona: '+'`, `$pripona: ' nezapočteno'`) i „500 Kč stranou"
     * (`$pripona: ' stranou'`). Prázdné `$castky` nikdy netvoří větu s holou
     * předponou nebo příponou — proto se test na prázdno dělá tady, ne u volajícího.
     *
     * @param  array<string, float>  $castky
     */
    public static function poznamka(array $castky, callable $format, string $oddelovac = ' + ', string $predpona = '', string $pripona = ''): string
    {
        return $castky === [] ? '' : $predpona.self::spoj($castky, $oddelovac, $format).$pripona;
    }

    /**
     * Částka s pevnou mezerou v tisících, ať se na úvodní obrazovce a v týdnu nezalomí.
     *
     * Obyčejná mezera (`Meny::castka()`) je zalomitelná — prohlížeč by „12 345 Kč"
     * mohl rozdělit na dva řádky uprostřed čísla.
     */
    public static function castkaPevnaMezera(float $castka, ?string $mena): string
    {
        return number_format($castka, 0, ',', "\u{00A0}").' '.Meny::znak($mena);
    }
}
