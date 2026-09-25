<?php

namespace App\Services\Finance;

use App\Models\GallerySpace;
use App\Services\Integrations\FreeTravelDataService;
use App\Support\Cas;
use App\Support\Meny;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Kurzy měn pro rozpočty.
 *
 * Rozpočty dosud měny zásadně nesčítaly, protože „kurz nemáme odkud vzít". To ale
 * přestalo platit: aplikace už kurzy tahá pro cestovní část z Frankfurteru, což jsou
 * kurzy ECB, zdarma a bez klíče. Nevěděly o tom jen rozpočty.
 *
 * Přepočet nikdy nenahrazuje původní částku. Uložená položka si svou měnu drží a
 * všechny součty po měnách zůstávají tím hlavním, co obrazovka ukazuje. Přepočet je
 * druhá, výslovně označená informace, protože odpovídá na otázku, na kterou se jinak
 * odpovědět nedá: „kolik jsme dohromady utratili", když jeden platí v eurech a druhý
 * v korunách.
 *
 * U každého přepočtu se posílá i datum kurzu. Číslo bez data by se tvářilo jako fakt,
 * přitom je to snímek jednoho dne — a kdo podle něj vyrovnává dluh, ten rozdíl chce vidět.
 */
class ExchangeRateService
{
    /**
     * Kurzy se drží den.
     *
     * ECB je vydává jednou denně kolem poledne. Ptát se častěji nemá co přinést a
     * zbytečně to zatěžuje cizí službu, která nás pouští zadarmo.
     */
    private const DRZET_HODIN = 24;

    /** Když se kurz nepodaří získat, drží se prázdná odpověď krátce — ať se to zkusí znovu. */
    private const DRZET_SELHANI_MINUT = 20;

    /**
     * Jak dlouho se na kurz čeká.
     *
     * Kurzy se tahají při načítání obrazovky. Když Frankfurter nejede, je lepší
     * ukázat součty po měnách bez přepočtu, než nechat dvojici dívat se osm sekund
     * na prázdný rozpočet (výchozí čekání integrací).
     */
    private const CEKAT_SEKUND = 3;

    /** Pod půl haléře je to zaokrouhlovací šum, ne částka. */
    private const NULA = 0.005;

    public function __construct(private readonly FreeTravelDataService $data) {}

    /**
     * Kolik cílové měny je za jednotku zdrojové.
     *
     * Null znamená „nevím" — ne jedna. Kdyby se při výpadku vracela jednička, sečetly by
     * se koruny s eury jako by si byly rovny a výsledek by vypadal důvěryhodně.
     *
     * @return array{rate: float, date: string}|null
     */
    public function rate(string $z, string $na): ?array
    {
        return $this->kurz($z, $na);
    }

    /**
     * Součet částek po měnách v hlavní měně prostoru (CZK).
     *
     * Na rozdíl od `combine()` rozliší „není co přepočítat" od „přepočítat nejde":
     *
     * - nic nebo samé nuly → celkem 0, úplné, nic se nepřepočítalo;
     * - jen hlavní měna → prostý součet, na kurz se vůbec nesahá;
     * - víc měn → každá měna se sečte zvlášť, zaokrouhlí na haléře a přepočte jednou
     *   (ne po položkách — sto drobných přepočtů by nasbíralo zaokrouhlovací chybu);
     * - chybí-li jediný kurz → `celkem` je null a `chybi` říká, které měny. Smíšené
     *   číslo se neukáže vůbec, polovičatý součet vypadá stejně důvěryhodně jako úplný.
     *   Součty po měnách a kurzy, které se získat podařilo, se vracejí i tak.
     *
     * Ruční tabulka `currency_rates` záložní zdroj **není**: je to kurz, za který
     * dvojice kdysi měnila, ne dnešní hodnota — a přepočet by pak tiše míchal obojí.
     *
     * Klíče se berou velkými písmeny, takže „eur" a „EUR" jsou jedna měna. Prázdný
     * klíč je hlavní měna (starší zápisy měnu nemají, psalo se jen v korunách); klíč,
     * který kódem měny není, se nepřepočítá a skončí v `chybi`.
     *
     * @param  array<string, float|int|string>  $poMenach  měna => částka
     * @return array{mena: string, celkem: ?float, uplne: bool, prepocteno: bool, kurzKeDni: ?string, kurzy: array<string, float>, poMenach: array<string, float>, chybi: list<string>}
     */
    public function doHlavni(array $poMenach, GallerySpace|int|null $prostor = null): array
    {
        $hlavni = Meny::hlavni($prostor);
        $soucty = $this->sectiPoMenach($poMenach, $hlavni);

        $celkem = 0.0;
        $kurzy = [];
        $chybi = [];
        $datum = null;
        // Jakmile jeden dotaz na kurz selže, další měny téhož součtu se už nezkoušejí.
        // Služba, která neodpověděla na eura, neodpoví za vteřinu ani na dolary — a dvě
        // měny by jinak znamenaly dvakrát čekat.
        $smiNaSit = true;

        foreach ($soucty as $mena => $castka) {
            if ($mena === $hlavni) {
                $celkem += $castka;

                continue;
            }

            $kurz = Meny::kod($mena) === null ? null : $this->kurz($mena, $hlavni, $smiNaSit);

            if ($kurz === null) {
                $chybi[] = $mena;

                continue;
            }

            $celkem += $castka * $kurz['rate'];
            $kurzy[$mena] = $kurz['rate'];
            // Nejstarší z použitých kurzů — souhrn není čerstvější než jeho nejstarší část.
            $datum = $datum === null || $kurz['date'] < $datum ? $kurz['date'] : $datum;
        }

        $uplne = $chybi === [];

        return [
            'mena' => $hlavni,
            'celkem' => $uplne ? round($celkem, 2) : null,
            'uplne' => $uplne,
            'prepocteno' => $uplne && $kurzy !== [],
            'kurzKeDni' => $datum,
            'kurzy' => $kurzy,
            'poMenach' => $soucty,
            'chybi' => $chybi,
        ];
    }

    /**
     * Popisek k přepočtenému součtu: „přepočteno kurzem ECB k 24. 9. 2026".
     *
     * Null, když se nic nepřepočítalo — u čistě korunového součtu by věta o kurzu jen
     * mátla, a u neúplného se číslo neukazuje, takže není k čemu ji psát.
     *
     * @param  array{prepocteno?: bool, kurzKeDni?: ?string}  $vysledek  výstup `doHlavni()`
     */
    public function popisek(array $vysledek): ?string
    {
        $den = $vysledek['kurzKeDni'] ?? null;

        if (! ($vysledek['prepocteno'] ?? false) || ! is_string($den) || $den === '') {
            return null;
        }

        try {
            $den = CarbonImmutable::createFromFormat('!Y-m-d', $den);
        } catch (\Throwable) {
            return null;
        }

        return $den instanceof CarbonImmutable ? 'přepočteno kurzem ECB k '.$den->format('j. n. Y') : null;
    }

    /**
     * Částky sečtené po měnách: klíče velkými písmeny, na haléře, bez nul.
     *
     * @param  array<string, float|int|string>  $poMenach
     * @return array<string, float>
     */
    private function sectiPoMenach(array $poMenach, string $hlavni): array
    {
        $soucty = [];

        foreach ($poMenach as $mena => $castka) {
            $kod = strtoupper(trim((string) $mena));
            $kod = $kod === '' ? $hlavni : $kod;
            $soucty[$kod] = ($soucty[$kod] ?? 0.0) + (is_numeric($castka) ? (float) $castka : 0.0);
        }

        $zaokrouhlene = array_map(fn (float $castka) => round($castka, 2), $soucty);

        return array_filter($zaokrouhlene, fn (float $castka) => abs($castka) >= self::NULA);
    }

    /**
     * Kurz z paměti, a když tam není, ze sítě.
     *
     * S `$smiNaSit = false` se vrací jen to, co už je v paměti. Po neúspěšném dotazu
     * volajícímu dá vědět tím, že mu `$smiNaSit` shodí — ať se na síť neptá znovu.
     *
     * @return array{rate: float, date: string}|null
     */
    private function kurz(string $z, string $na, bool &$smiNaSit = true): ?array
    {
        $z = strtoupper($z);
        $na = strtoupper($na);

        if ($z === $na) {
            return ['rate' => 1.0, 'date' => Cas::dnes()->toDateString()];
        }

        $klic = "fx:{$z}:{$na}";

        $ulozene = Cache::get($klic);

        // Zapamatované selhání. Rozlišuje se od „nic tu není" tím, že je to pole s false.
        if ($ulozene === false) {
            return null;
        }

        if (is_array($ulozene)) {
            return $ulozene;
        }

        if (! $smiNaSit) {
            return null;
        }

        try {
            $odpoved = $this->data->rate($z, $na, null, self::CEKAT_SEKUND);

            // Ověřený tvar: {"date":"2026-08-24","base":"EUR","quote":"CZK","rate":24.1}
            $kurz = isset($odpoved['rate']) ? (float) $odpoved['rate'] : null;

            if (! $kurz || $kurz <= 0) {
                throw new \RuntimeException('Odpověď neobsahuje použitelný kurz.');
            }

            $vysledek = ['rate' => $kurz, 'date' => (string) ($odpoved['date'] ?? Cas::dnes()->toDateString())];
            Cache::put($klic, $vysledek, now()->addHours(self::DRZET_HODIN));

            return $vysledek;
        } catch (\Throwable $problem) {
            // Výpadek kurzů nesmí položit obrazovku s rozpočtem. Přehled se vykreslí
            // bez přepočtu, což je přesně stav, ve kterém aplikace fungovala dosud.
            Log::warning('Kurz se nepodařilo získat.', ['z' => $z, 'na' => $na, 'chyba' => $problem->getMessage()]);
            Cache::put($klic, false, now()->addMinutes(self::DRZET_SELHANI_MINUT));
            $smiNaSit = false;

            return null;
        }
    }

    /**
     * Sečte částky v různých měnách do jedné.
     *
     * Vrací null, jakmile chybí kurz k jediné z nich — polovičatý součet je horší než
     * žádný, protože vypadá stejně důvěryhodně jako úplný.
     *
     * @param  array<string, float>  $castky  měna => částka
     * @return array{total: float, currency: string, date: string, rates: array<string, float>}|null
     */
    public function combine(array $castky, string $cilova): ?array
    {
        $castky = array_filter($castky, fn ($c) => abs((float) $c) > 0.004);

        if ($castky === []) {
            return null;
        }

        // Jediná měna, a to ta cílová — sčítat není co a přepočet by jen mátl.
        if (array_keys($castky) === [strtoupper($cilova)]) {
            return null;
        }

        $celkem = 0.0;
        $kurzy = [];
        $datum = null;

        foreach ($castky as $mena => $castka) {
            $kurz = $this->rate((string) $mena, $cilova);

            if ($kurz === null) {
                return null;
            }

            $celkem += (float) $castka * $kurz['rate'];
            $kurzy[strtoupper((string) $mena)] = $kurz['rate'];
            // Nejstarší z použitých kurzů — souhrn není čerstvější než jeho nejstarší část.
            $datum = $datum === null || $kurz['date'] < $datum ? $kurz['date'] : $datum;
        }

        return [
            'total' => round($celkem, 2),
            'currency' => strtoupper($cilova),
            'date' => (string) $datum,
            'rates' => $kurzy,
        ];
    }
}
