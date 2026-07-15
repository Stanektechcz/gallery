<?php

namespace App\Services\Planning;

use Carbon\Carbon;

/** Czech days off and deterministic opportunities for a shared long weekend. */
class CzechPublicHolidayService
{
    private const SOURCE = 'Zákon č. 245/2000 Sb.';

    /** @return array<int, array<string, mixed>> */
    public function between(Carbon $from, Carbon $to): array
    {
        $holidays = [];
        for ($year = $from->year; $year <= $to->year; $year++) {
            array_push($holidays, ...$this->forYear($year));
        }

        return collect($holidays)
            ->filter(fn (array $holiday) => $holiday['date'] >= $from->toDateString() && $holiday['date'] <= $to->toDateString())
            ->sortBy('date')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function opportunities(Carbon $from, Carbon $to): array
    {
        $expandedFrom = $from->copy()->subDays(7);
        $expandedTo = $to->copy()->addDays(7);
        $holidayByDate = collect($this->between($expandedFrom, $expandedTo))->keyBy('date');
        $candidates = [];

        foreach ($holidayByDate->values() as $holiday) {
            $holidayDate = Carbon::parse($holiday['date'], 'Europe/Prague');
            if ($holiday['date'] < $from->toDateString() || $holiday['date'] > $to->toDateString() || $holidayDate->isWeekend()) continue;

            $windows = match ($holidayDate->dayOfWeekIso) {
                1 => [[-2, 0]],
                2 => [[-3, 0]],
                3 => [[0, 4], [-4, 0]],
                4 => [[0, 3]],
                5 => [[0, 2]],
                default => [],
            };

            foreach ($windows as [$startOffset, $endOffset]) {
                $start = $holidayDate->copy()->addDays($startOffset)->startOfDay();
                $end = $holidayDate->copy()->addDays($endOffset)->endOfDay();
                $leaveDays = [];
                $windowHolidays = [];

                for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                    $key = $date->toDateString();
                    if ($holidayByDate->has($key)) {
                        $windowHolidays[$key] = $holidayByDate->get($key)['title'];
                    } elseif (! $date->isWeekend()) {
                        $leaveDays[] = $key;
                    }
                }

                if (count($leaveDays) > 2) continue;
                $duration = (int) $start->diffInDays($end->copy()->startOfDay()) + 1;
                $key = $start->toDateString() . '|' . $end->toDateString();
                $candidate = [
                    'id' => substr(hash('sha256', 'cz|' . $key), 0, 20),
                    'title' => 'Společné volno: ' . reset($windowHolidays),
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'duration_days' => $duration,
                    'leave_days' => $leaveDays,
                    'leave_days_count' => count($leaveDays),
                    'holiday_dates' => array_keys($windowHolidays),
                    'holiday_titles' => array_values($windowHolidays),
                    'source' => self::SOURCE,
                    '_score' => ($duration * 10) - (count($leaveDays) * 12) + ($start->isSameDay($holidayDate) ? 1 : 0),
                ];

                if (! isset($candidates[$key]) || $candidate['_score'] > $candidates[$key]['_score']) {
                    $candidates[$key] = $candidate;
                }
            }
        }

        $ranked = collect($candidates)->sortByDesc('_score')->values();
        $selected = [];
        foreach ($ranked as $candidate) {
            $duplicatesHolidayWindow = collect($selected)->contains(function (array $existing) use ($candidate) {
                return count(array_intersect($existing['holiday_dates'], $candidate['holiday_dates'])) > 0;
            });
            if (! $duplicatesHolidayWindow) $selected[] = $candidate;
        }

        return collect($selected)
            ->sortBy('start_date')
            ->map(function (array $candidate) {
                unset($candidate['_score']);
                return $candidate;
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function forYear(int $year): array
    {
        $easter = $this->easterSunday($year);
        $fixed = [
            ['01-01', 'Den obnovy samostatného českého státu a Nový rok', 'state'],
            ['05-01', 'Svátek práce', 'other'],
            ['05-08', 'Den vítězství', 'state'],
            ['07-05', 'Den slovanských věrozvěstů Cyrila a Metoděje', 'state'],
            ['07-06', 'Den upálení mistra Jana Husa', 'state'],
            ['09-28', 'Den české státnosti', 'state'],
            ['10-28', 'Den vzniku samostatného československého státu', 'state'],
            ['11-17', 'Den boje za svobodu a demokracii a Mezinárodní den studentstva', 'state'],
            ['12-24', 'Štědrý den', 'other'],
            ['12-25', '1. svátek vánoční', 'other'],
            ['12-26', '2. svátek vánoční', 'other'],
        ];
        $rows = [
            $this->holiday($easter->copy()->subDays(2), 'Velký pátek', 'other'),
            $this->holiday($easter->copy()->addDay(), 'Velikonoční pondělí', 'other'),
        ];
        foreach ($fixed as [$date, $title, $type]) {
            $rows[] = $this->holiday(Carbon::parse("{$year}-{$date}", 'Europe/Prague'), $title, $type);
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private function holiday(Carbon $date, string $title, string $type): array
    {
        $weekdayLabels = [1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle'];
        return [
            'date' => $date->toDateString(),
            'title' => $title,
            'type' => $type,
            'is_day_off' => true,
            'is_weekend' => $date->isWeekend(),
            'weekday' => $date->dayOfWeekIso,
            'weekday_label' => $weekdayLabels[$date->dayOfWeekIso],
            'source' => self::SOURCE,
        ];
    }

    private function easterSunday(int $year): Carbon
    {
        // Gregorian Meeus/Jones/Butcher algorithm, independent of server locale.
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return Carbon::create($year, $month, $day, 0, 0, 0, 'Europe/Prague');
    }
}
