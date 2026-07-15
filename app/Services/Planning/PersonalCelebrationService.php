<?php

namespace App\Services\Planning;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Important Czech name days shared throughout the partner workspace. */
class PersonalCelebrationService
{
    /**
     * The display names intentionally use the names the couple uses day to day.
     * Dates follow the Czech civil name-day calendar.
     */
    private const HIGHLIGHTED_NAME_DAYS = [
        ['name' => 'Jana', 'official_name' => 'Jana', 'month' => 5, 'day' => 24],
        ['name' => 'Adrian', 'official_name' => 'Adrian / Adriana', 'month' => 6, 'day' => 26],
        ['name' => 'Šárka', 'official_name' => 'Šárka', 'month' => 6, 'day' => 30],
        ['name' => 'Markétka', 'official_name' => 'Markéta', 'month' => 7, 'day' => 13],
        ['name' => 'Andrea', 'official_name' => 'Andrea', 'month' => 9, 'day' => 26],
        ['name' => 'Vašek', 'official_name' => 'Václav', 'month' => 9, 'day' => 28],
    ];

    public function between(Carbon $from, Carbon $to): Collection
    {
        return collect(range($from->year, $to->year))
            ->flatMap(fn (int $year) => collect(self::HIGHLIGHTED_NAME_DAYS)->map(function (array $item) use ($year) {
                $date = Carbon::create($year, $item['month'], $item['day'], 12, 0, 0, 'Europe/Prague');

                return [
                    'id' => sprintf('name-day-%s-%d', str($item['official_name'])->slug(), $year),
                    'date' => $date->toDateString(),
                    'name' => $item['name'],
                    'official_name' => $item['official_name'],
                    'title' => "Svátek: {$item['name']}",
                    'icon' => '🎈',
                    'is_highlighted' => true,
                ];
            }))
            ->filter(fn (array $item) => $item['date'] >= $from->toDateString() && $item['date'] <= $to->toDateString())
            ->sortBy('date')
            ->values();
    }
}
