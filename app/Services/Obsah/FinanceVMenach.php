<?php

namespace App\Services\Obsah;

use App\Models\Budget;
use App\Models\GallerySpace;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\SouctyPoMenach;
use App\Support\Meny;
use Illuminate\Support\Collection;

/**
 * Peníze `Finance` v hlavní měně prostoru — a jinde, kam je „Finance" posílá.
 *
 * Souhrn účtů, kdo co zaplatil a čerpání rozpočtu ve víc měnách patřily dřív
 * do `Finance`, která tím narostla na dvojnásobek. Sem šlo přesně to, co si
 * vystačí s prostorem a kurzem a nepotřebuje nic dalšího z `Finance` — tahle
 * třída se dá pochopit (a otestovat) bez zbytku obrazovky.
 */
class FinanceVMenach
{
    /**
     * Hlavní měna podle prostoru — jednou za sestavení obsahu.
     *
     * @var array<int, string>
     */
    private array $hlavniMena = [];

    /**
     * Kurzy do hlavní měny, které už se zjistily: „prostor:měna" => kurz, nebo null.
     *
     * @var array<string, array{rate: float, date: ?string}|null>
     */
    private array $kurzyPamet = [];

    public function __construct(private readonly ExchangeRateService $kurzy) {}

    /**
     * Hlavní měna prostoru — jednou za sestavení obsahu.
     *
     * `Meny::hlavni()` je dotaz do databáze a tady by se ptal u každého ze sto
     * dvaceti řádků transakcí. Paměť drží instance, ne třída: poskytovatel se
     * pro každý požadavek skládá znovu, takže do jiného prostoru nepřežije.
     */
    public function hlavni(GallerySpace $prostor): string
    {
        return $this->hlavniMena[(int) $prostor->id] ??= Meny::hlavni($prostor);
    }

    /** Měna rozpočtu velkými písmeny; rozpočet bez měny je v hlavní měně. */
    public function menaRozpoctu(GallerySpace $prostor, Budget $rozpocet): string
    {
        return Meny::kod($rozpocet->currency) ?? $this->hlavni($prostor);
    }

    /**
     * Kurz měny do hlavní měny prostoru, nebo null, když ho neznáme.
     *
     * Kurzy drží na den `ExchangeRateService`; tady se jen neptá znovu u každého
     * řádku. Null znamená „nevím", ne jedna — kdo by počítal s jedničkou, sečetl
     * by koruny s eury jako rovné.
     *
     * @return array{rate: float, date: ?string}|null
     */
    public function kurz(GallerySpace $prostor, string $mena): ?array
    {
        if ($mena === $this->hlavni($prostor)) {
            return ['rate' => 1.0, 'date' => null];
        }

        $klic = $prostor->id.':'.$mena;

        if (! array_key_exists($klic, $this->kurzyPamet)) {
            $vysledek = $this->kurzy->doHlavni([$mena => 1], $prostor);

            $this->kurzyPamet[$klic] = isset($vysledek['kurzy'][$mena])
                ? ['rate' => (float) $vysledek['kurzy'][$mena], 'date' => $vysledek['kurzKeDni']]
                : null;
        }

        return $this->kurzyPamet[$klic];
    }

    /** Částka v hlavní měně, nebo null bez kurzu. */
    public function vHlavni(GallerySpace $prostor, float $castka, string $mena): ?float
    {
        $kurz = $this->kurz($prostor, $mena);

        return $kurz === null ? null : $castka * $kurz['rate'];
    }

    /**
     * Výdaje sečtené po měnách: `měna => částka` (kladně).
     *
     * @param  Collection<int, object>  $pohyby
     * @return array<string, float>
     */
    public function poMenach(Collection $pohyby, string $bezMeny): array
    {
        return SouctyPoMenach::secti($pohyby, fn ($t) => $t->currency_from, fn ($t) => abs((float) $t->amount_from), $bezMeny);
    }

    /**
     * Částky po měnách v měně `$mena`: co přepočítat jde, přepočte; co ne, vrátí zvlášť.
     *
     * Na rozdíl od `ExchangeRateService::doHlavni()` bez kurzu nevrací null.
     * Čerpání rozpočtu se nemá tvářit, že se neutrácelo vůbec: započte se, co
     * přepočítat jde, a zbytek se ukáže jako „+40 € nezapočteno". Přepočítává
     * se jen do hlavní měny — jiné kurzy aplikace nemá, takže rozpočtu v eurech
     * zůstane cizí měna vždycky nezapočtená.
     *
     * @param  array<string, float>  $poMenach
     * @return array{castka: float, prepocteno: array<string, float>, nezapocteno: array<string, float>, prevod: float, den: ?string}
     */
    public function doMeny(GallerySpace $prostor, array $poMenach, string $mena): array
    {
        $vysledek = ['castka' => 0.0, 'prepocteno' => [], 'nezapocteno' => [], 'prevod' => 0.0, 'den' => null];
        $doHlavni = $mena === $this->hlavni($prostor);

        foreach ($poMenach as $kod => $castka) {
            $kod = (string) $kod;

            if ($kod === $mena) {
                $vysledek['castka'] += $castka;

                continue;
            }

            $kurz = $doHlavni ? $this->kurz($prostor, $kod) : null;

            if ($kurz === null) {
                $vysledek['nezapocteno'][$kod] = ($vysledek['nezapocteno'][$kod] ?? 0.0) + $castka;

                continue;
            }

            $vysledek['castka'] += $castka * $kurz['rate'];
            $vysledek['prevod'] += $castka * $kurz['rate'];
            $vysledek['prepocteno'][$kod] = ($vysledek['prepocteno'][$kod] ?? 0.0) + $castka;
            $vysledek['den'] = $this->starsiDen($vysledek['den'], $kurz['date']);
        }

        return $vysledek;
    }

    /**
     * Popisek k součtům, které prošly `doMeny()`: kurz, nezapočtené, nebo nic.
     *
     * @param  list<array{prepocteno: array<string, float>, nezapocteno: array<string, float>, den: ?string}>  $vysledky
     */
    public function poznamkaPrepoctu(array $vysledky): string
    {
        $den = null;
        $prepocteno = false;
        $nezapocteno = [];

        foreach ($vysledky as $v) {
            $prepocteno = $prepocteno || $v['prepocteno'] !== [];
            $den = $this->starsiDen($den, $v['den']);

            foreach ($v['nezapocteno'] as $kod => $castka) {
                $nezapocteno[$kod] = ($nezapocteno[$kod] ?? 0.0) + $castka;
            }
        }

        return implode(' · ', array_filter([
            $this->kurzy->popisek(['prepocteno' => $prepocteno, 'kurzKeDni' => $den]) ?? '',
            $this->nezapoctenoText($nezapocteno),
        ]));
    }

    /** Starší ze dvou dnů kurzu — souhrn není čerstvější než jeho nejstarší část. */
    public function starsiDen(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $b < $a ? $b : $a;
    }

    /**
     * „1 000 Kč · 100 €" — součty po měnách vedle sebe, bez přepočtu.
     *
     * @param  array<string, float>  $poMenach
     */
    public function poMenachText(array $poMenach): string
    {
        return SouctyPoMenach::spoj($poMenach, ' · ', fn (float $castka, string $kod) => Meny::castka($castka, $kod));
    }

    /**
     * „+40 € nezapočteno" — co bez kurzu do součtu nešlo. Prázdné, když nic.
     *
     * @param  array<string, float>  $nezapocteno
     */
    public function nezapoctenoText(array $nezapocteno): string
    {
        return SouctyPoMenach::poznamka(
            $nezapocteno,
            fn (float $castka, string $kod) => Meny::castka($castka, $kod),
            ' + ',
            '+',
            ' nezapočteno',
        );
    }

    /**
     * Částky na haléře, jak je vrací i `doHlavni()`.
     *
     * @param  array<string, float>  $poMenach
     * @return array<string, float>
     */
    public function zaokrouhlene(array $poMenach): array
    {
        return SouctyPoMenach::zaokrouhli($poMenach);
    }
}
