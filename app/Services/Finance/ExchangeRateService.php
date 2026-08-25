<?php

namespace App\Services\Finance;

use App\Services\Integrations\FreeTravelDataService;
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
        $z = strtoupper($z);
        $na = strtoupper($na);

        if ($z === $na) {
            return ['rate' => 1.0, 'date' => now()->toDateString()];
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

        try {
            $odpoved = $this->data->rate($z, $na);

            // Ověřený tvar: {"date":"2026-08-24","base":"EUR","quote":"CZK","rate":24.1}
            $kurz = isset($odpoved['rate']) ? (float) $odpoved['rate'] : null;

            if (! $kurz || $kurz <= 0) {
                throw new \RuntimeException('Odpověď neobsahuje použitelný kurz.');
            }

            $vysledek = ['rate' => $kurz, 'date' => (string) ($odpoved['date'] ?? now()->toDateString())];
            Cache::put($klic, $vysledek, now()->addHours(self::DRZET_HODIN));

            return $vysledek;
        } catch (\Throwable $problem) {
            // Výpadek kurzů nesmí položit obrazovku s rozpočtem. Přehled se vykreslí
            // bez přepočtu, což je přesně stav, ve kterém aplikace fungovala dosud.
            Log::warning('Kurz se nepodařilo získat.', ['z' => $z, 'na' => $na, 'chyba' => $problem->getMessage()]);
            Cache::put($klic, false, now()->addMinutes(self::DRZET_SELHANI_MINUT));

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
