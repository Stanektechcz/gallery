<?php

namespace App\Services\Obsah;

use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Models\WellbeingMood;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Cyklus a nálada ve tvaru, ve kterém je kreslí prototyp.
 *
 * Kalendář cyklu je **soukromý zápis jednoho člověka**, ne společný obsah páru.
 * Aplikace na to má nastavení sdílení (`cycle_settings.share_level`) a tenhle
 * poskytovatel ho drží: partner uvidí jen to, co mu ta druhá strana pustila.
 * Poslat mu všechno „protože jsou pár" by bylo přesně to, čemu se ta volba má
 * vyhnout.
 */
class Zdravi implements PoskytovatelObsahu
{
    /** Kolik dní zpátky kreslí křivka nálady. */
    private const DNU_NALADY = 14;

    public function skupina(): string
    {
        return 'zdravi';
    }

    /**
     * Zapsané dny přicházejí celé.
     *
     * Ukázkový cyklus vedle skutečného by znamenal krvácení v den, kdy žádné
     * nebylo — a podle toho se odhaduje ten příští.
     */
    public function uplne(): array
    {
        return ['CYC_BASE'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $dny = Schema::hasTable('cycle_days') ? $this->dny($prostor) : collect();

        $zacatky = $this->zacatky($dny);

        return array_filter([
            'CYC_BASE' => $this->zapsane($dny),
            'CYC_STARTS' => $zacatky,
            'KL_DAYS' => $this->popiskyDnu(),
            'KL_MOOD' => $this->nalady($prostor),
            // Přehled cyklu ve sloupcích — úzké rozvržení kreslí záložku
            // „Přehled" z `ABARS`, ne z vlastní obrazovky.
            'ABARS' => ($c = $this->sloupceCyklu($zacatky)) ? ['cycle' => $c] : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Zapsané dny — cizí jen podle úrovně sdílení.
     *
     * @return Collection<int, CycleDay>
     */
    private function dny(GallerySpace $prostor): Collection
    {
        $ja = auth()->id();

        $dny = CycleDay::where('gallery_space_id', $prostor->id)
            ->where('day', '>=', CarbonImmutable::now()->subYear())
            ->orderBy('day')
            ->get();

        $urovne = Schema::hasTable('cycle_settings')
            ? CycleSetting::where('gallery_space_id', $prostor->id)->pluck('share_level', 'user_id')
            : collect();

        return $dny
            ->filter(function (CycleDay $d) use ($ja, $urovne) {
                if ($d->user_id === $ja) {
                    return true;
                }

                // Bez výslovného svolení se cizí zápis neposílá vůbec.
                return ($urovne[$d->user_id] ?? CycleSetting::SHARE_NONE) !== CycleSetting::SHARE_NONE;
            })
            ->map(function (CycleDay $d) use ($ja, $urovne) {
                if ($d->user_id === $ja
                    || ($urovne[$d->user_id] ?? CycleSetting::SHARE_NONE) === CycleSetting::SHARE_FULL) {
                    return $d;
                }

                // „Jen termíny": partner vidí, kdy čekat a kolikátý den je.
                // Příznaky, nálada, bolest ani poznámka mu do toho nic nejsou.
                $osekany = clone $d;
                $osekany->symptoms = [];
                $osekany->moods = [];
                $osekany->pain = null;
                $osekany->temperature = null;
                $osekany->note = null;

                return $osekany;
            })
            ->values();
    }

    /**
     * Zapsané dny jako mapa `datum => { flow, symptoms, moods, … }`.
     *
     * @param  Collection<int, CycleDay>  $dny
     * @return array<string, array<string, mixed>>
     */
    private function zapsane(Collection $dny): array
    {
        $mapa = [];

        foreach ($dny as $d) {
            $klic = CarbonImmutable::parse($d->day)->format('Y-m-d');

            $mapa[$klic] = [
                'day' => $klic,
                'flow' => $d->flow ?: 'none',
                'symptoms' => $d->symptoms ?: [],
                'moods' => $d->moods ?: [],
                'pain' => $d->pain !== null ? (int) $d->pain : null,
                'temp' => $d->temperature !== null ? (float) $d->temperature : null,
                'note' => $d->note,
                'start' => (bool) $d->is_cycle_start,
                // Zapsaný den má přednost před odhadem — proto nikdy `true`.
                'predicted' => false,
            ];
        }

        return $mapa;
    }

    /**
     * Začátky cyklů: `[[první den krvácení, kolik dní trvalo], …]`.
     *
     * Odvozuje se ze zapsaných dnů, ne z druhého seznamu: z něj se počítá délka
     * cyklu i odhad toho příštího, takže dvě pravdy by znamenaly dva různé
     * odhady na jedné obrazovce.
     *
     * @param  Collection<int, CycleDay>  $dny
     * @return list<array<int, mixed>>
     */
    /**
     * Přehled cyklu ve sloupcích: `[popisek, údaj, %, barva]`.
     *
     * Průměrná délka i předpověď stojí na zaznamenaných začátcích. Ze dvou
     * začátků je jedna délka a z jedné délky se průměr nedělá — pod tři se
     * proto neposílá nic. Odhad z jednoho čísla vypadá stejně jistě jako
     * odhad z roku záznamů, a to je na tomhle nejhorší.
     *
     * @param  list<array{0: string, 1: int}>  $zacatky
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function sloupceCyklu(array $zacatky): array
    {
        if (count($zacatky) < 3) {
            return [];
        }

        $delky = [];

        for ($i = 1; $i < count($zacatky); $i++) {
            $delky[] = (int) CarbonImmutable::parse($zacatky[$i - 1][0])
                ->diffInDays(CarbonImmutable::parse($zacatky[$i][0]));
        }

        $prumer = array_sum($delky) / count($delky);
        $posledni = CarbonImmutable::parse($zacatky[count($zacatky) - 1][0]);
        $den = (int) $posledni->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()) + 1;
        $pristi = $posledni->addDays((int) round($prumer));

        return [
            [
                'Aktuální den cyklu',
                'den '.$den.' z '.round($prumer),
                (int) round(min(100, $den / max(1, $prumer) * 100)),
                1,
            ],
            [
                'Průměrná délka',
                str_replace('.', ',', (string) round($prumer, 1)).' dne · '
                    .$this->pocet(count($delky), 'zaznamenaný cyklus', 'zaznamenané cykly', 'zaznamenaných cyklů'),
                100,
                0,
            ],
            [
                'Předpověď příště',
                $pristi->format('j. n.'),
                (int) round(min(100, max(0, 100 - $posledni->diffInDays(CarbonImmutable::now()) / max(1, $prumer) * 100))),
                2,
            ],
        ];
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $kolik.' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }

    private function zacatky(Collection $dny): array
    {
        $krvaceni = $dny
            ->filter(fn (CycleDay $d) => $d->flow && $d->flow !== 'none')
            ->keyBy(fn (CycleDay $d) => CarbonImmutable::parse($d->day)->format('Y-m-d'));

        return $dny
            ->filter(fn (CycleDay $d) => $d->is_cycle_start)
            ->map(function (CycleDay $d) use ($krvaceni) {
                $den = CarbonImmutable::parse($d->day);
                $delka = 0;

                // Kolik dní v řadě od začátku ještě teklo.
                while ($krvaceni->has($den->addDays($delka)->format('Y-m-d'))) {
                    $delka++;
                }

                return [$den->format('Y-m-d'), max(1, $delka)];
            })
            ->values()
            ->all();
    }

    /**
     * Popisky posledních čtrnácti dnů: `['22. 8.', '23. 8.', …]`.
     *
     * @return list<string>
     */
    private function popiskyDnu(): array
    {
        $od = CarbonImmutable::now()->startOfDay()->subDays(self::DNU_NALADY - 1);

        return collect(range(0, self::DNU_NALADY - 1))
            ->map(fn (int $i) => $od->addDays($i)->format('j. n.'))
            ->all();
    }

    /**
     * Nálada obou: `{ 'Adrian': [4, 4, 2, …], 'Makinka': […] }`.
     *
     * Chybějící den je `null`, ne nula: „nezapsáno" a „bylo mi mizerně" nejsou
     * totéž a křivka by z toho udělala pád.
     *
     * @return array<string, list<int|null>>
     */
    private function nalady(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('wellbeing_moods')) {
            return [];
        }

        $od = CarbonImmutable::now()->startOfDay()->subDays(self::DNU_NALADY - 1);

        $zapsane = WellbeingMood::where('gallery_space_id', $prostor->id)
            ->where('day', '>=', $od)
            ->get()
            ->groupBy('user_id');

        if ($zapsane->isEmpty()) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();
        $vysledek = [];

        foreach ($jmena as $id => $jmeno) {
            $podleDne = ($zapsane[$id] ?? collect())
                ->keyBy(fn (WellbeingMood $m) => CarbonImmutable::parse($m->day)->format('Y-m-d'));

            $vysledek[$jmeno] = collect(range(0, self::DNU_NALADY - 1))
                ->map(function (int $i) use ($od, $podleDne) {
                    $den = $od->addDays($i)->format('Y-m-d');

                    return isset($podleDne[$den]) ? (int) $podleDne[$den]->value : null;
                })
                ->all();
        }

        return $vysledek;
    }
}
