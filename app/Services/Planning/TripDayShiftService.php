<?php

namespace App\Services\Planning;

use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dny cesty (`trip_days`) a události kalendáře drží krok s termínem cesty.
 *
 * Termín cesty se mění ve starém API (`TripController::update()`) i přes
 * hlavní kartu v kalendáři (`CalendarPlanningController::syncTripSchedule()`).
 * Dřív se posunuly nanejvýš události: dny zůstaly na starém termínu, „Teď"
 * ukazovalo první starý den, deník během cesty nenašel svůj den, rezervace
 * a jídla se k němu nepřiřadily a galerie zakládala nové dny vedle starých.
 *
 * Dny se proto posouvají o stejný rozdíl jako události — o posun začátku
 * cesty — a řádky zůstávají (na dni visí body programu, deník, jídla,
 * podklady z inboxu i importy rezervací). Smazat se smí jen den, na kterém
 * nic není: `trip_activities` visí na dni s `cascadeOnDelete`, smazání dne by
 * tedy potichu smazalo i program.
 */
class TripDayShiftService
{
    /** Nejdelší cesta ve dnech — stejný strop jako `CalendarEventTripService::createDays()`. */
    public const MAX_DNI = 366;

    /** Název, který den dostane při založení; jiný napsal člověk a ten se nepřepisuje. */
    private const VYCHOZI_NAZEV = '/^Den \d+$/u';

    /** Tabulky se sloupcem `trip_day_id` — řádek v kterékoli z nich znamená, že den není prázdný. */
    private const NA_DNI_VISI = ['trip_activities', 'travel_journal_entries', 'planned_meals', 'travel_inbox_items', 'trip_reservation_imports'];

    /**
     * Posune dny cesty ze starého termínu na nový.
     *
     * Posun je rozdíl začátků (jako u událostí): samotné prodloužení nebo
     * zkrácení konce nechá dny na místě a jen doplní nebo uklidí ty na konci.
     */
    public function posun(int $tripId, string|CarbonInterface $oldStart, string|CarbonInterface $oldEnd, string|CarbonInterface $newStart, string|CarbonInterface $newEnd): void
    {
        $puvodniZacatek = $this->datum($oldStart);
        $zacatek = $this->datum($newStart);
        $konec = $this->datum($newEnd);
        if ($puvodniZacatek->equalTo($zacatek) && $this->datum($oldEnd)->equalTo($konec)) {
            return;
        }
        $posun = (int) round($puvodniZacatek->diffInDays($zacatek, false));

        DB::transaction(function () use ($tripId, $posun, $zacatek, $konec): void {
            // Cesta bez dnů je dostane až při prvním otevření plánu
            // (`zajisti()`); tady by je zakládalo každé uložení termínu.
            if (! DB::table('trip_days')->where('trip_id', $tripId)->exists()) {
                return;
            }
            if ($posun !== 0) {
                $this->posunDny($tripId, $posun);
                $this->posunJidla($tripId, $posun);
            }
            $this->srovnejDny($tripId, $zacatek, $konec);
        });
    }

    /**
     * Dny odpovídají termínu cesty — levná kontrola při čtení plánu.
     *
     * Chybí-li v termínu nějaký den (cesta bez dnů, prodloužená před touto
     * opravou, nebo den založený galerií jednotlivě), dny se srovnají.
     */
    public function zajisti(object $trip): void
    {
        $zacatek = $this->datum($trip->start_date);
        $konec = $this->datum($trip->end_date);
        $ocekavano = min(self::MAX_DNI, (int) round($zacatek->diffInDays($konec, false)) + 1);
        $sloupec = DB::getQueryGrammar()->wrap('date');
        $dny = DB::table('trip_days')->where('trip_id', $trip->id)
            ->selectRaw('count(*) as vsech')
            ->selectRaw("sum(case when {$sloupec} >= ? and {$sloupec} <= ? then 1 else 0 end) as v_terminu", [$zacatek->toDateString(), $konec->toDateString()])
            ->first();
        // Den mimo termín (zastaralý po dřívějším posunu) také spustí srovnání —
        // prázdný se uklidí a pořadí se srovná po datech.
        if ((int) $dny->v_terminu >= $ocekavano && (int) $dny->vsech === (int) $dny->v_terminu) {
            return;
        }

        DB::transaction(fn () => $this->srovnejDny((int) $trip->id, $zacatek, $konec));
    }

    /** Id dne cesty s daným datem; dny se nejdřív srovnají s termínem. */
    public function denCesty(object $trip, string|CarbonInterface $datum): ?int
    {
        $this->zajisti($trip);
        $id = DB::table('trip_days')->where('trip_id', $trip->id)->whereDate('date', $this->datum($datum)->toDateString())->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Posune události kalendáře navázané na cestu, když se změnil její termín.
     *
     * Dřív každé uložení s datem (i beze změny) přepsalo datum **všech**
     * událostí s `trip_id` na začátek a konec cesty — večerní vlak z
     * posledního dne tak skončil na prvním dni a úkoly přišly o své termíny.
     *
     * - Hlavní karta cesty dostane nový termín; časy dne zůstanou. Hlavní je
     *   karta `type = trip`, nebo ta, která pokrývá právě celý dosavadní
     *   termín — kalendář ji umí založit s jiným typem a termín cesty podle
     *   ní řídí (`CalendarPlanningController::syncTripSchedule()`).
     * - Ostatní navázané události (rezervace, úkoly…) se posunou o tolik dní,
     *   o kolik se posunul začátek cesty. Samotné prodloužení nebo zkrácení
     *   konce je nechá na místě.
     *
     * S událostí se posunou i její čekající připomínky, o stejný rozdíl.
     * `$kromeUdalosti` je karta, která termín právě změnila v kalendáři —
     * ta už nové datum má.
     */
    public function posunUdalosti(object $before, object $trip, ?int $kromeUdalosti = null): void
    {
        $oldStart = Carbon::parse($before->start_date)->startOfDay();
        $oldEnd = Carbon::parse($before->end_date)->startOfDay();
        $newStart = Carbon::parse($trip->start_date)->startOfDay();
        $newEnd = Carbon::parse($trip->end_date)->startOfDay();
        if ($oldStart->equalTo($newStart) && $oldEnd->equalTo($newEnd)) {
            return;
        }
        $shiftDays = (int) round($oldStart->diffInDays($newStart, false));

        $events = DB::table('calendar_events')->where('trip_id', $trip->id)->where('gallery_space_id', $trip->gallery_space_id)
            ->when($kromeUdalosti !== null, fn ($query) => $query->where('id', '!=', $kromeUdalosti))
            ->get();
        foreach ($events as $event) {
            $startsAt = Carbon::parse($event->starts_at);
            $endsAt = $event->ends_at ? Carbon::parse($event->ends_at) : null;
            $isMain = $event->type === 'trip'
                || ($startsAt->isSameDay($oldStart) && ($endsAt ?? $startsAt)->isSameDay($oldEnd));
            if (! $isMain && $shiftDays === 0) {
                continue;
            }
            $newStartsAt = $isMain ? $startsAt->copy()->setDateFrom($newStart) : $startsAt->copy()->addDays($shiftDays);
            $update = ['starts_at' => $newStartsAt, 'updated_at' => now()];
            if ($endsAt) {
                $update['ends_at'] = $isMain ? $endsAt->copy()->setDateFrom($newEnd) : $endsAt->copy()->addDays($shiftDays);
            }
            DB::table('calendar_events')->where('id', $event->id)->update($update);

            $seconds = $newStartsAt->getTimestamp() - $startsAt->getTimestamp();
            if ($seconds !== 0) {
                foreach (DB::table('event_reminders')->where('event_id', $event->id)->where('status', 'pending')->get(['id', 'remind_at']) as $reminder) {
                    DB::table('event_reminders')->where('id', $reminder->id)->update(['remind_at' => Carbon::parse($reminder->remind_at)->addSeconds($seconds), 'updated_at' => now()]);
                }
            }
        }
    }

    /**
     * Každý den o `$posun` dní, řádek po řádku.
     *
     * `unique(trip_id, date)` hlídá každý jednotlivý UPDATE: při posunu
     * dopředu jde první nejpozdější den (na volné datum za koncem), při
     * posunu dozadu nejdřívější — žádný den tak nepřistane na datu, které
     * ještě drží jiný, dosud neposunutý den.
     */
    private function posunDny(int $tripId, int $posun): void
    {
        $dny = DB::table('trip_days')->where('trip_id', $tripId)->orderBy('date', $posun > 0 ? 'desc' : 'asc')->get(['id', 'date']);
        foreach ($dny as $den) {
            DB::table('trip_days')->where('id', $den->id)->update([
                'date' => $this->datum($den->date)->addDays($posun)->toDateString(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Jídla naplánovaná na den cesty jdou s ním.
     *
     * Jejich karta v kalendáři má `trip_id`, takže ji posune `posunUdalosti()`;
     * `planned_for` jídla a vaření by jinak zůstal na starém dni
     * (`MealPlanController` páruje jídlo se dnem přes datum).
     */
    private function posunJidla(int $tripId, int $posun): void
    {
        if (! Tabulky::sloupec('planned_meals', 'trip_day_id')) {
            return;
        }
        $jidla = DB::table('planned_meals as meal')
            ->join('trip_days as day', 'day.id', '=', 'meal.trip_day_id')
            ->where('day.trip_id', $tripId)
            ->get(['meal.id', 'meal.planned_for', 'meal.cooking_session_id']);
        $vareni = Tabulky::sloupec('recipe_cooking_sessions', 'planned_for');
        foreach ($jidla as $jidlo) {
            DB::table('planned_meals')->where('id', $jidlo->id)->update([
                'planned_for' => $this->posunutyCas($jidlo->planned_for, $posun),
                'updated_at' => now(),
            ]);
            if ($vareni && $jidlo->cooking_session_id) {
                $kdy = DB::table('recipe_cooking_sessions')->where('id', $jidlo->cooking_session_id)->value('planned_for');
                if ($kdy !== null) {
                    DB::table('recipe_cooking_sessions')->where('id', $jidlo->cooking_session_id)->update([
                        'planned_for' => $this->posunutyCas($kdy, $posun),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Doplní dny v termínu, uklidí prázdné mimo něj a přečísluje pořadí.
     *
     * Den mimo termín, na kterém něco je, zůstává — raději den navíc
     * v itineráři než ztracený program. Pořadí jde po datech (dřív galerie
     * zakládala den s pořadím podle nového začátku vedle starých dnů se
     * stejným pořadím) a výchozí názvy „Den N" se přečíslují s ním.
     */
    private function srovnejDny(int $tripId, CarbonImmutable $zacatek, CarbonImmutable $konec): void
    {
        $radky = [];
        for ($den = $zacatek, $i = 0; $den->lte($konec) && $i < self::MAX_DNI; $den = $den->addDay(), $i++) {
            $radky[] = [
                'trip_id' => $tripId, 'date' => $den->toDateString(), 'title' => 'Den '.($i + 1),
                'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($radky, 100) as $davka) {
            DB::table('trip_days')->insertOrIgnore($davka);
        }

        $mimo = DB::table('trip_days')->where('trip_id', $tripId)
            ->where(fn ($query) => $query->whereDate('date', '<', $zacatek->toDateString())->orWhereDate('date', '>', $konec->toDateString()))
            ->get(['id', 'title', 'notes']);
        foreach ($mimo as $den) {
            if ($this->jePrazdny($den)) {
                DB::table('trip_days')->where('id', $den->id)->delete();
            }
        }

        $dny = DB::table('trip_days')->where('trip_id', $tripId)->orderBy('date')->orderBy('id')->get(['id', 'title', 'sort_order']);
        foreach ($dny->values() as $poradi => $den) {
            $zmena = [];
            if ((int) $den->sort_order !== $poradi) {
                $zmena['sort_order'] = $poradi;
            }
            $nazev = 'Den '.($poradi + 1);
            if ($den->title !== null && $den->title !== $nazev && preg_match(self::VYCHOZI_NAZEV, $den->title) === 1) {
                $zmena['title'] = $nazev;
            }
            if ($zmena !== []) {
                DB::table('trip_days')->where('id', $den->id)->update($zmena + ['updated_at' => now()]);
            }
        }
    }

    private function jePrazdny(object $den): bool
    {
        if (trim((string) $den->notes) !== '') {
            return false;
        }
        if ($den->title !== null && trim($den->title) !== '' && preg_match(self::VYCHOZI_NAZEV, $den->title) !== 1) {
            return false;
        }
        foreach (self::NA_DNI_VISI as $tabulka) {
            if (Tabulky::sloupec($tabulka, 'trip_day_id') && DB::table($tabulka)->where('trip_day_id', $den->id)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** Čas jídla zůstává na hodinách — posouvá se jen den. */
    private function posunutyCas(mixed $kdy, int $posun): string
    {
        return Carbon::parse($kdy)->addDays($posun)->format('Y-m-d H:i:s');
    }

    private function datum(mixed $hodnota): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $hodnota, 0, 10))->startOfDay();
    }
}
