<?php

namespace App\Services\Health;

use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menstruační kalendář — odvození cyklů a předpovědi.
 *
 * Předpovědi se nikam neukládají. Počítají se z historie při každém dotazu, aby se po
 * doplnění zapomenutého dne rovnou opravily; uložená předpověď by po týdnu tiše lhala.
 *
 * A všude, kde se něco odhaduje, se říká z čeho: „podle posledních tří cyklů" znamená
 * něco jiného než „podle výchozích 28 dní", a člověk, který plánuje dovolenou, ten
 * rozdíl potřebuje znát.
 */
class CycleService
{
    /** Pod tolik cyklů se průměr nepočítá — dva údaje nejsou historie. */
    private const MIN_CYCLES_FOR_AVERAGE = 2;

    /** Cyklus kratší nebo delší než tohle je zápis omylem, ne cyklus. */
    private const MIN_CYCLE_DAYS = 15;
    private const MAX_CYCLE_DAYS = 60;

    public function settings(GallerySpace $space, User $user): CycleSetting
    {
        return CycleSetting::firstOrCreate(
            ['user_id' => $user->id, 'gallery_space_id' => $space->id],
        );
    }

    /**
     * Celý přehled pro jednoho člověka.
     *
     * @return array<string, mixed>
     */
    public function overview(GallerySpace $space, User $owner, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $settings = $this->settings($space, $owner);

        $days = CycleDay::where('user_id', $owner->id)
            ->orderBy('day')
            ->get();

        $cycles = $this->cycles($days);
        $averages = $this->averages($cycles, $settings);

        return [
            'settings' => [
                'share_level' => $settings->share_level,
                'average_cycle_days' => $averages['cycle'],
                'average_period_days' => $averages['period'],
                'based_on_cycles' => $averages['based_on'],
                'remind_upcoming' => $settings->remind_upcoming,
                'remind_days_before' => $settings->remind_days_before,
                'track_symptoms' => $settings->track_symptoms,
            ],
            'days' => $days->map(fn (CycleDay $den) => [
                'uuid' => $den->uuid,
                'day' => $den->day->toDateString(),
                'flow' => $den->flow,
                'symptoms' => $den->symptoms ?? [],
                'moods' => $den->moods ?? [],
                'pain' => $den->pain,
                'temperature' => $den->temperature !== null ? (float) $den->temperature : null,
                'note' => $den->note,
                'is_cycle_start' => $den->is_cycle_start,
                'is_predicted' => $den->is_predicted,
            ])->values()->all(),
            'cycles' => $cycles->map(fn (array $c) => [
                'started_on' => $c['start']->toDateString(),
                'ended_on' => $c['end']?->toDateString(),
                'length' => $c['length'],
                'period_days' => $c['period_days'],
            ])->values()->all(),
            'prediction' => $this->predict($cycles, $averages, $today),
            'today' => $this->describeToday($cycles, $averages, $today),
            // Měsíc dopředu, den po dni. Souhrnné „menstruace 30. července" je na
            // plánování dovolené málo — kalendář má ukázat celý nadcházející měsíc.
            'forecast' => $this->forecast($space, $owner, 40, $today),
        ];
    }

    /**
     * Rozdělí zapsané dny na cykly.
     *
     * Nový cyklus začíná ručně označeným dnem, nebo prvním krvácením po aspoň dvou dnech
     * pauzy. Ta pauza je tam schválně: krvácení se často na den přeruší a bez ní by se
     * jeden cyklus rozpadl na tři.
     *
     * @return Collection<int, array{start: Carbon, end: ?Carbon, length: ?int, period_days: int}>
     */
    private function cycles(Collection $days): Collection
    {
        // Jen zapsané dny. Předvyplněné odhady sem nesmí: aplikace by odhadla pět dní,
        // z nich spočítala průměr pět, tím potvrdila sama sebe — a že tenhle cyklus trval
        // tři dny, by se nikdy nedozvěděla.
        $krvaceni = $days->filter(fn (CycleDay $d) => $d->isBleeding() && $d->isRecorded())->values();
        if ($krvaceni->isEmpty()) return collect();

        $zacatky = collect();
        $predchozi = null;

        foreach ($krvaceni as $den) {
            $novy = $den->is_cycle_start
                || $predchozi === null
                || $predchozi->day->diffInDays($den->day) > 2;

            if ($novy) $zacatky->push($den->day->copy());

            $predchozi = $den;
        }

        return $zacatky->map(function (Carbon $start, int $index) use ($zacatky, $krvaceni) {
            $dalsi = $zacatky->get($index + 1);

            // Délka cyklu je vzdálenost k dalšímu začátku. Poslední cyklus délku nemá —
            // ještě neskončil, a dopočítat ji z předpovědi by znamenalo vydávat odhad
            // za změřený údaj.
            $delka = $dalsi ? (int) $start->diffInDays($dalsi) : null;

            $dnyKrvaceni = $krvaceni->filter(function (CycleDay $d) use ($start, $dalsi) {
                return $d->day->greaterThanOrEqualTo($start)
                    && ($dalsi === null || $d->day->lessThan($dalsi));
            })->count();

            return [
                'start' => $start,
                'end' => $dalsi?->copy()->subDay(),
                'length' => $delka !== null && $delka >= self::MIN_CYCLE_DAYS && $delka <= self::MAX_CYCLE_DAYS ? $delka : $delka,
                'period_days' => $dnyKrvaceni,
            ];
        });
    }

    /**
     * Průměry ze skutečnosti, dokud je z čeho.
     *
     * Nesmyslně krátké i dlouhé cykly se do průměru nepočítají — jeden zápis omylem by
     * jinak posunul předpověď o týden a nikdo by nevěděl proč.
     */
    private function averages(Collection $cycles, CycleSetting $settings): array
    {
        $delky = $cycles->pluck('length')
            ->filter(fn ($d) => $d !== null && $d >= self::MIN_CYCLE_DAYS && $d <= self::MAX_CYCLE_DAYS)
            ->values();

        // Probíhající cyklus se do průměru délky krvácení nepočítá.
        //
        // Má zatím zapsaný jeden nebo dva dny prostě proto, že ještě neskončil, a průměr
        // by tím strhával dolů — po zadání prvního dne by aplikace „zjistila", že
        // menstruace trvá kratší dobu, a podle toho předvyplnila míň dnů příště. Poznají
        // se podle toho, že nemají délku: tu dostane cyklus až tím, že začne další.
        $krvaceni = $cycles
            ->filter(fn (array $c) => $c['length'] !== null)
            ->pluck('period_days')
            ->filter(fn ($d) => $d > 0 && $d <= 14)
            ->values();

        return [
            'cycle' => $delky->count() >= self::MIN_CYCLES_FOR_AVERAGE
                ? (int) round($delky->avg())
                : (int) $settings->average_cycle_days,
            'period' => $krvaceni->count() >= self::MIN_CYCLES_FOR_AVERAGE
                ? (int) round($krvaceni->avg())
                : (int) $settings->average_period_days,
            'based_on' => $delky->count(),
            // Rozptyl: u nepravidelného cyklu je předpověď orientační a má to být vidět.
            'spread' => $delky->count() >= self::MIN_CYCLES_FOR_AVERAGE
                ? (int) ($delky->max() - $delky->min())
                : null,
        ];
    }

    /**
     * Kdy čekat příští menstruaci a plodné dny.
     *
     * Ovulace se odvozuje od konce cyklu, ne od začátku: luteální fáze je u většiny lidí
     * stabilních čtrnáct dní, kdežto první polovina se protahuje. Počítat plodné dny od
     * začátku by u delšího cyklu ukázalo špatný týden.
     */
    private function predict(Collection $cycles, array $averages, Carbon $today): ?array
    {
        $posledni = $cycles->last();
        if (! $posledni) return null;

        $dalsi = $posledni['start']->copy()->addDays($averages['cycle']);

        // Když už měla dávno začít, posouváme po celých cyklech dál — jinak by aplikace
        // tvrdila, že menstruace „měla přijít před třemi týdny", což nikomu nepomůže.
        while ($dalsi->lessThan($today->copy()->subDays(3))) {
            $dalsi->addDays($averages['cycle']);
        }

        $ovulace = $dalsi->copy()->subDays(14);

        return [
            'next_period_on' => $dalsi->toDateString(),
            'days_until' => (int) $today->diffInDays($dalsi, false),
            'period_ends_on' => $dalsi->copy()->addDays(max(1, $averages['period']) - 1)->toDateString(),
            'ovulation_on' => $ovulace->toDateString(),
            'fertile_from' => $ovulace->copy()->subDays(5)->toDateString(),
            'fertile_to' => $ovulace->copy()->addDay()->toDateString(),
            'based_on_cycles' => $averages['based_on'],
            'spread_days' => $averages['spread'],
            // Odhad, ne diagnóza — a čím míň cyklů, tím volnější.
            'confidence' => match (true) {
                $averages['based_on'] >= 6 && ($averages['spread'] ?? 99) <= 4 => 'high',
                $averages['based_on'] >= 3 => 'medium',
                default => 'low',
            },
        ];
    }

    /** Kolikátý den cyklu je dnes a v jaké fázi. */
    private function describeToday(Collection $cycles, array $averages, Carbon $today): ?array
    {
        $posledni = $cycles->last();
        if (! $posledni) return null;

        $den = (int) $posledni['start']->diffInDays($today) + 1;
        if ($den < 1) return null;

        $ovulace = $averages['cycle'] - 14;

        return [
            'cycle_day' => $den,
            'phase' => match (true) {
                $den <= max(1, $averages['period']) => 'menstruation',
                $den < $ovulace - 4 => 'follicular',
                $den <= $ovulace + 1 => 'fertile',
                default => 'luteal',
            },
        ];
    }

    /**
     * Co uvidí partner.
     *
     * Nefiltruje se to až na obrazovce — sem se nedostane nic, co majitelka nesdílí.
     * Skrývat na frontendu údaj, který server odeslal, je iluze soukromí.
     */
    public function partnerView(GallerySpace $space, User $owner, ?Carbon $today = null): ?array
    {
        $settings = $this->settings($space, $owner);
        if (! $settings->allowsPartner()) return null;

        $plny = $this->overview($space, $owner, $today);

        if (! $settings->allowsDetail()) {
            // Jen kdy čekat. Žádné příznaky, žádné poznámky, žádná teplota.
            return [
                'owner' => ['id' => $owner->id, 'name' => $owner->name],
                'share_level' => $settings->share_level,
                'prediction' => $plny['prediction'],
                'today' => $plny['today'],
            ];
        }

        return [
            'owner' => ['id' => $owner->id, 'name' => $owner->name],
            'share_level' => $settings->share_level,
            'prediction' => $plny['prediction'],
            'today' => $plny['today'],
            'days' => $plny['days'],
            'cycles' => $plny['cycles'],
        ];
    }

    /**
     * Dá partnerovi vědět, že to začalo.
     *
     * Jen když si to majitelka sdílí — a jen první den, ne každý zápis. Smysl je
     * praktický: partner ví, proč je jí zle, a nemusí se ptát.
     */
    private function announceStart(GallerySpace $space, User $owner, CycleDay $den): void
    {
        $settings = $this->settings($space, $owner);
        if (! $settings->allowsPartner()) return;

        // Jen skutečný začátek: předchozí dva dny bez krvácení. Bez téhle podmínky by
        // zpráva odešla i za den, který se doplňoval zpětně uprostřed menstruace.
        $predchozi = CycleDay::where('user_id', $owner->id)
            ->whereBetween('day', [$den->day->copy()->subDays(2), $den->day->copy()->subDay()])
            ->get()
            ->contains(fn (CycleDay $d) => $d->isBleeding());

        if ($predchozi) return;

        $klic = "cycle:announced:{$owner->id}:" . $den->day->toDateString();
        if (! \Illuminate\Support\Facades\Cache::add($klic, true, now()->addDays(3))) return;

        foreach ($space->members()->where('users.id', '!=', $owner->id)->get() as $partner) {
            $partner->notify(new \App\Notifications\GalleryNotification(
                'health.cycle',
                $owner->name . ' má první den cyklu.',
                '/cyklus',
                '🩸',
                ['owner_id' => $owner->id],
            ));
        }
    }

    /**
     * Předvyplní zbytek menstruace po zadání prvního dne.
     *
     * Zadat, že to začalo, a pak ještě čtyřikrát ťuknout na další dny je práce, kterou
     * aplikace umí udělat sama — jak dlouho krvácení obvykle trvá, ví z historie.
     *
     * Jsou to odhady, ne záznamy: kreslí se jinak, do statistik nevstupují a jakmile se
     * jich člověk dotkne, přestávají být odhadem. Den, který už zapsaný je, se nepřepisuje
     * nikdy — skutečnost má vždycky přednost před tím, co jsme si mysleli.
     *
     * Intenzita klesá, protože tak menstruace obvykle probíhá; kdo to má jinak, přepíše
     * dva dny místo pěti.
     *
     * @return int Kolik dnů skutečně přibylo.
     */
    public function autofillPeriod(GallerySpace $space, User $user, CycleDay $prvni): int
    {
        $settings = $this->settings($space, $user);
        $days = CycleDay::where('user_id', $user->id)->orderBy('day')->get();
        $prumer = $this->averages($this->cycles($days), $settings)['period'];

        $pribylo = 0;

        for ($posun = 1; $posun < max(1, $prumer); $posun++) {
            $datum = $prvni->day->copy()->addDays($posun);

            // Do budoucnosti se nepředvyplňuje přes konec očekávaného krvácení a už vůbec
            // ne přes dny, které si člověk zapsal sám.
            if (CycleDay::where('user_id', $user->id)->whereDate('day', $datum)->exists()) continue;

            CycleDay::create([
                'user_id' => $user->id,
                'gallery_space_id' => $space->id,
                'day' => $datum,
                'flow' => $posun <= 1 ? 'medium' : ($posun < $prumer - 1 ? 'light' : 'spotting'),
                'is_predicted' => true,
            ]);

            $pribylo++;
        }

        return $pribylo;
    }

    /**
     * Den po dni na měsíc dopředu.
     *
     * Souhrnná předpověď říká „menstruace 30. července" a to je málo — kalendář má
     * ukázat celý nadcházející měsíc obarvený podle fází, aby šlo naplánovat dovolenou
     * nebo návštěvu, aniž by si to člověk musel dopočítávat v hlavě.
     *
     * @return array<int, array{day: string, phase: string, confidence: string}>
     */
    public function forecast(GallerySpace $space, User $owner, int $dnu = 40, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $days = CycleDay::where('user_id', $owner->id)->orderBy('day')->get();
        $cycles = $this->cycles($days);
        if ($cycles->isEmpty()) return [];

        $settings = $this->settings($space, $owner);
        $averages = $this->averages($cycles, $settings);
        $predpoved = $this->predict($cycles, $averages, $today);
        if (! $predpoved) return [];

        // Zapsané dny se do předpovědi nepletou — co je zapsané, se nepředpovídá.
        $zapsane = $days->filter(fn (CycleDay $d) => $d->isRecorded())->keyBy(fn (CycleDay $d) => $d->day->toDateString());

        $zacatek = Carbon::parse($predpoved['next_period_on']);
        $vysledek = [];

        for ($i = 0; $i < $dnu; $i++) {
            $datum = $today->copy()->addDays($i);
            $klic = $datum->toDateString();

            if ($zapsane->has($klic)) continue;

            // Kolikátý den kterého očekávaného cyklu — počítá se dopředu po celých
            // cyklech, aby předpověď nekončila prvním z nich.
            $odZacatku = (int) $zacatek->diffInDays($datum, false);
            while ($odZacatku < 0) {
                $zacatek->subDays($averages['cycle']);
                $odZacatku = (int) $zacatek->diffInDays($datum, false);
            }

            $vCyklu = $odZacatku % max(1, $averages['cycle']);
            $ovulace = $averages['cycle'] - 14;

            $faze = match (true) {
                $vCyklu < max(1, $averages['period']) => 'menstruation',
                $vCyklu >= $ovulace - 5 && $vCyklu <= $ovulace + 1 => 'fertile',
                // PMS: posledních pár dní před očekávaným krvácením. Vyznačit je stojí za
                // to — je to týden, kdy člověk chce vědět, proč mu je, jak mu je.
                $vCyklu >= $averages['cycle'] - 4 => 'pms',
                $vCyklu < $ovulace - 5 => 'follicular',
                default => 'luteal',
            };

            $vysledek[] = [
                'day' => $klic,
                'phase' => $faze,
                // Čím dál do budoucna, tím volnější odhad — druhý cyklus dopředu stojí
                // na tom, že ten první vyjde přesně, což skoro nikdy nevyjde.
                'confidence' => $odZacatku > $averages['cycle'] ? 'low' : $predpoved['confidence'],
            ];
        }

        return $vysledek;
    }

    /** Zapíše nebo přepíše jeden den. */
    public function saveDay(GallerySpace $space, User $user, array $data): CycleDay
    {
        // Datum se normalizuje na půlnoc, protože podle něj se řádek hledá. Uložená
        // hodnota je '2026-05-05 00:00:00' a hledat podle '2026-05-05' ji v SQLite
        // nenajde — updateOrCreate pak místo úpravy zkusí vložit druhý řádek na týž den
        // a spadne na jedinečném klíči. Přepsat existující den je přitom to, co člověk
        // v kalendáři dělá nejčastěji.
        $den = CycleDay::updateOrCreate(
            ['user_id' => $user->id, 'day' => Carbon::parse($data['day'])->startOfDay()],
            [
                'gallery_space_id' => $space->id,
                'flow' => $data['flow'] ?? 'none',
                'symptoms' => $data['symptoms'] ?? null,
                'moods' => $data['moods'] ?? null,
                'pain' => $data['pain'] ?? null,
                'temperature' => $data['temperature'] ?? null,
                'note' => $data['note'] ?? null,
                'is_cycle_start' => $data['is_cycle_start'] ?? false,
                // Cokoli přijde odsud, zapsal člověk — i když to přepisuje dřívější odhad.
                'is_predicted' => false,
            ],
        );

        if ($den->isBleeding()) {
            $this->announceStart($space, $user, $den);

            // Předvyplní zbytek jen u prvního dne. Uprostřed menstruace by to nedávalo
            // smysl a na konci by dopisovalo dny, které už minuly.
            $predchoziKrvacel = CycleDay::where('user_id', $user->id)
                ->whereBetween('day', [$den->day->copy()->subDays(2), $den->day->copy()->subDay()])
                ->get()
                ->contains(fn (CycleDay $d) => $d->isBleeding());

            if (! $predchoziKrvacel) {
                $this->autofillPeriod($space, $user, $den);
            }
        }

        return $den;
    }

    /**
     * Statistika: jak se cyklus choval v čase a co ho doprovází.
     *
     * Odděleně od přehledu, protože se dívá dozadu přes celou historii — u někoho, kdo
     * zapisuje třetí rok, je to podstatně dražší dotaz než dnešní stav.
     *
     * @return array<string, mixed>
     */
    public function statistics(GallerySpace $space, User $owner): array
    {
        $days = CycleDay::where('user_id', $owner->id)->orderBy('day')->get();
        $cycles = $this->cycles($days);
        $settings = $this->settings($space, $owner);
        $averages = $this->averages($cycles, $settings);

        $delky = $cycles->pluck('length')->filter()->values();

        // Který příznak se v které fázi objevuje. Ne diagnóza — jen „tohle už znáš",
        // což u opakujících se bolestí hlavy stojí za vidění.
        $vFazi = [];

        foreach ($cycles as $cyklus) {
            $konec = $cyklus['end'] ?? Carbon::today();

            foreach ($days as $den) {
                if ($den->day->lessThan($cyklus['start']) || $den->day->greaterThan($konec)) continue;

                $poradi = (int) $cyklus['start']->diffInDays($den->day) + 1;
                $faze = match (true) {
                    $poradi <= max(1, $averages['period']) => 'menstruation',
                    $poradi < $averages['cycle'] - 18 => 'follicular',
                    $poradi <= $averages['cycle'] - 13 => 'fertile',
                    default => 'luteal',
                };

                foreach (($den->symptoms ?? []) as $priznak) {
                    $vFazi[$priznak][$faze] = ($vFazi[$priznak][$faze] ?? 0) + 1;
                }
            }
        }

        // Jen to, co se opakovalo — jeden výskyt není vzorec.
        $vzorce = collect($vFazi)
            ->map(function (array $faze, string $priznak) {
                arsort($faze);

                return [
                    'symptom' => $priznak,
                    'phase' => array_key_first($faze),
                    'count' => array_sum($faze),
                    'in_phase' => reset($faze),
                ];
            })
            ->filter(fn (array $v) => $v['count'] >= 3)
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'cycle_lengths' => $cycles->filter(fn ($c) => $c['length'] !== null)
                ->map(fn ($c) => ['started_on' => $c['start']->toDateString(), 'length' => $c['length'], 'period_days' => $c['period_days']])
                ->values()->all(),
            'shortest' => $delky->min(),
            'longest' => $delky->max(),
            'average' => $averages['cycle'],
            'spread' => $averages['spread'],
            'tracked_days' => $days->count(),
            'symptom_patterns' => $vzorce,
        ];
    }
}
