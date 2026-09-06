<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Integrations\FreeTravelDataService;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Předpověď počasí na pět dní — pro „co uvařit".
 *
 * Obrazovka podle ní řadí návrhy: teplá polévka do sychravého dne, focaccia
 * k salátu, když se dá sedět venku. Dosud to bylo pět řádků napsaných
 * v souboru s ukázkovými daty, takže „první opravdu letní den týdne" platil
 * i v listopadu.
 *
 * Zdroj není nový: aplikace už Open-Meteo volá u cest (`FreeTravelDataService`).
 * Nové je jen to, odkud se bere **místo**.
 *
 * Kde dvojice je, se nikde nezadává. Bere se proto **medián polohy jejich
 * fotek** za posledního půl roku: kde se nejčastěji fotí, tam se nejčastěji
 * vaří. Medián, ne průměr — dva týdny u moře by průměr odtáhly do Jaderského
 * moře a aplikace by radila podle počasí, které nikdo nemá za oknem.
 *
 * Souřadnice se zaokrouhlují na dvě desetiny stupně (zhruba kilometr). Na
 * počasí to nemá vliv a ven z aplikace neodchází přesnější poloha, než je
 * k té odpovědi potřeba.
 */
class Predpoved
{
    /** Kolik dní dopředu obrazovka kreslí. */
    private const DNU = 5;

    /** Jak dlouho platí stažená předpověď. */
    private const CERSTVOST_MINUT = 180;

    /** Kolik měsíců zpátky se hledá, kde dvojice bývá. */
    private const MESICU_POLOHY = 6;

    private const DNY = [1 => 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];

    public function __construct(private readonly FreeTravelDataService $sluzba) {}

    /**
     * `[[den, ikona, max, min, režim, věta]]`, nebo prázdno.
     *
     * @return list<array<int, mixed>>
     */
    public function naPetDni(GallerySpace $prostor): array
    {
        $kde = $this->kdeJsou($prostor);

        if ($kde === null) {
            return [];
        }

        [$sirka, $delka] = $kde;

        $data = Cache::remember(
            'galerie.pocasi.'.$prostor->id.'.'.$sirka.'.'.$delka,
            now()->addMinutes(self::CERSTVOST_MINUT),
            function () use ($sirka, $delka) {
                try {
                    return $this->sluzba->weather($sirka, $delka);
                } catch (\Throwable $e) {
                    // Nedostupná předpověď není chyba aplikace. Obrazovka si
                    // nechá, co má, a zkusí se to za tři hodiny znovu.
                    return null;
                }
            },
        );

        return $data === null ? [] : $this->radky($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<int, mixed>>
     */
    private function radky(array $data): array
    {
        $denne = $data['daily'] ?? [];
        $dny = $denne['time'] ?? [];

        if (! is_array($dny) || $dny === []) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();
        $radky = [];

        foreach (array_slice($dny, 0, self::DNU) as $i => $den) {
            $datum = CarbonImmutable::parse($den);
            $max = (int) round((float) ($denne['temperature_2m_max'][$i] ?? 0));
            $min = (int) round((float) ($denne['temperature_2m_min'][$i] ?? 0));
            // Služba si od Open-Meteo žádá **pravděpodobnost** srážek, ne
            // milimetry. Čtení `precipitation_sum` by tiše vracelo nulu
            // a každý den by vyšel jako suchý.
            $srazky = (float) ($denne['precipitation_probability_max'][$i] ?? 0);
            $kod = (int) ($denne['weather_code'][$i] ?? 0);

            $rezim = $this->rezim($max, $srazky, $kod);

            $radky[] = [
                $this->pojmenujDen($datum, $dnes),
                $this->ikona($kod, $srazky),
                $max,
                $min,
                $rezim,
                $this->veta($rezim, $max, $srazky, $kod),
            ];
        }

        return $radky;
    }

    /**
     * Režim dne. Čtyři hodnoty, protože jen ty čtyři umí obrazovka vážit
     * (`RWEATHER` je má v `fits` a skóre návrhu je porovnává).
     */
    private function rezim(int $max, float $srazky, int $kod): string
    {
        /*
         * Horko přebíjí déšť.
         *
         * Živá data to ukázala hned: třicet dva stupňů s odpolední přeháňkou
         * vycházelo jako „déšť" a obrazovka nabízela polévku. Teplota je to,
         * co rozhoduje, na co má člověk chuť; přeháňka na tom nic nemění.
         */
        if ($max >= 27) {
            return 'horko';
        }

        if ($srazky >= 50 || ($kod >= 51 && $kod <= 99)) {
            return 'déšť';
        }

        return $max >= 18 ? 'teplo' : 'chladno';
    }

    /** Kódy Open-Meteo (WMO) na ikony, které prototyp kreslí. */
    private function ikona(int $kod, float $srazky): string
    {
        return match (true) {
            $kod >= 95 => 'ph-cloud-lightning',
            $kod >= 71 && $kod <= 77 => 'ph-snowflake',
            $kod >= 80 || ($kod >= 51 && $kod <= 67) => 'ph-cloud-rain',
            $kod >= 45 && $kod <= 48 => 'ph-cloud-fog',
            $kod >= 2 => 'ph-cloud-sun',
            $srazky >= 50 => 'ph-cloud-rain',
            default => 'ph-sun',
        };
    }

    /**
     * Věta pod teplotou.
     *
     * Skládá se **jen z čísel, která přišla**. Ukázka měla „první opravdu
     * letní den týdne" a „vařit se nechce" — hezké věty o dni, který ještě
     * nebyl.
     */
    private function veta(string $rezim, int $max, float $srazky, int $kod): string
    {
        $obloha = match (true) {
            $kod >= 95 => 'bouřky',
            $kod >= 71 && $kod <= 77 => 'sněžení',
            $kod >= 80 => 'přeháňky',
            $kod >= 51 && $kod <= 67 => 'déšť',
            $kod >= 45 && $kod <= 48 => 'mlha',
            $kod >= 3 => 'zataženo',
            $kod >= 1 => 'polojasno',
            default => 'jasno',
        };

        $srazkyText = $srazky >= 20
            ? ', déšť na '.(int) round($srazky).' %'
            : '';

        return $obloha.', přes den '.$max.' °C'.$srazkyText;
    }

    private function pojmenujDen(CarbonImmutable $datum, CarbonImmutable $dnes): string
    {
        $rozdil = (int) $dnes->diffInDays($datum, false);

        return match ($rozdil) {
            0 => 'Dnes',
            1 => 'Zítra',
            default => self::DNY[$datum->dayOfWeekIso],
        };
    }

    /**
     * Kde dvojice bývá — medián polohy jejich fotek.
     *
     * @return array{0: float, 1: float}|null
     */
    private function kdeJsou(GallerySpace $prostor): ?array
    {
        $od = CarbonImmutable::now()->subMonths(self::MESICU_POLOHY);

        $body = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('taken_at', '>=', $od)
            ->limit(2000)
            ->get(['latitude', 'longitude']);

        if ($body->count() < 3) {
            return null;
        }

        return [
            round($this->median($body->pluck('latitude')->map(fn ($v) => (float) $v)->all()), 2),
            round($this->median($body->pluck('longitude')->map(fn ($v) => (float) $v)->all()), 2),
        ];
    }

    /** @param  list<float>  $hodnoty */
    private function median(array $hodnoty): float
    {
        sort($hodnoty);
        $stred = (int) floor(count($hodnoty) / 2);

        return count($hodnoty) % 2 === 1
            ? $hodnoty[$stred]
            : ($hodnoty[$stred - 1] + $hodnoty[$stred]) / 2;
    }
}
