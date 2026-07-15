<?php

namespace App\Services\Planning;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TripBudgetAdvisorService
{
    private const CATEGORIES = ['transport', 'accommodation', 'food', 'activities', 'insurance', 'other'];

    private const LABELS = [
        'transport' => 'Doprava',
        'accommodation' => 'Ubytování',
        'food' => 'Jídlo',
        'activities' => 'Aktivity',
        'insurance' => 'Pojištění',
        'other' => 'Ostatní',
    ];

    private const SHARES = [
        'economy' => ['transport' => 0.25, 'accommodation' => 0.35, 'food' => 0.20, 'activities' => 0.10, 'insurance' => 0.05, 'other' => 0.05],
        'balanced' => ['transport' => 0.25, 'accommodation' => 0.40, 'food' => 0.15, 'activities' => 0.12, 'insurance' => 0.04, 'other' => 0.04],
        'comfort' => ['transport' => 0.20, 'accommodation' => 0.50, 'food' => 0.15, 'activities' => 0.10, 'insurance' => 0.03, 'other' => 0.02],
    ];

    public function snapshot(object $trip): array
    {
        $currency = strtoupper($trip->currency ?? 'CZK');
        $profile = in_array($trip->budget_profile ?? null, array_keys(self::SHARES), true) ? $trip->budget_profile : 'balanced';
        $start = Carbon::parse($trip->start_date)->startOfDay();
        $end = Carbon::parse($trip->end_date)->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);
        $configuredBudget = isset($trip->budget) ? (float) $trip->budget : null;
        $configuredDaily = isset($trip->daily_budget_limit) ? (float) $trip->daily_budget_limit : null;
        $budget = $configuredBudget ?? ($configuredDaily !== null ? round($configuredDaily * $days, 2) : null);
        $dailyLimit = $configuredDaily ?? ($budget !== null ? round($budget / $days, 2) : null);

        $categories = collect(self::CATEGORIES)->mapWithKeys(fn (string $category) => [$category => [
            'category' => $category,
            'label' => self::LABELS[$category],
            'planned' => 0.0,
            'actual' => 0.0,
            'total' => 0.0,
            'limit' => null,
            'usage_percent' => null,
            'status' => 'unset',
        ]])->all();
        $daily = collect(CarbonPeriod::create($start, $end))->mapWithKeys(fn (Carbon $date) => [$date->toDateString() => [
            'date' => $date->toDateString(),
            'planned' => 0.0,
            'actual' => 0.0,
            'total' => 0.0,
            'limit' => $dailyLimit,
            'usage_percent' => null,
            'status' => $dailyLimit === null ? 'unset' : 'ok',
        ]])->all();
        $unconverted = [];
        $rateCache = [];

        foreach (DB::table('trip_expenses')->where('trip_id', $trip->id)->get() as $expense) {
            $amount = $this->convert((float) $expense->amount, $expense->currency, $currency, $expense->occurred_at, $rateCache);
            if ($amount === null) {
                $unconverted[] = ['source' => 'expense', 'id' => $expense->id, 'title' => $expense->title, 'currency' => $expense->currency];
                continue;
            }
            $category = array_key_exists($expense->category, $categories) ? $expense->category : 'other';
            $state = $expense->state === 'actual' ? 'actual' : 'planned';
            $categories[$category][$state] += $amount;
            $date = $expense->occurred_at ? Carbon::parse($expense->occurred_at)->toDateString() : null;
            if ($date && isset($daily[$date])) {
                $daily[$date][$state] += $amount;
            }
        }

        if (Schema::hasTable('trip_vehicle_costs')) {
            foreach (DB::table('trip_vehicle_costs')->where('trip_id', $trip->id)->get() as $cost) {
                $amount = $this->convert((float) $cost->amount, $cost->currency, $currency, $cost->occurred_on, $rateCache);
                if ($amount === null) {
                    $unconverted[] = ['source' => 'vehicle', 'id' => $cost->id, 'title' => $cost->title, 'currency' => $cost->currency];
                    continue;
                }
                $categories['transport']['actual'] += $amount;
                if ($cost->occurred_on && isset($daily[$cost->occurred_on])) {
                    $daily[$cost->occurred_on]['actual'] += $amount;
                }
            }
        }

        foreach (DB::table('trip_activities as activity')
            ->join('trip_days as day', 'day.id', '=', 'activity.trip_day_id')
            ->where('day.trip_id', $trip->id)
            ->whereNotNull('activity.cost')
            ->get(['activity.id', 'activity.title', 'activity.type', 'activity.cost', 'activity.currency', 'day.date']) as $activity) {
            $amount = $this->convert((float) $activity->cost, $activity->currency, $currency, $activity->date, $rateCache);
            if ($amount === null) {
                $unconverted[] = ['source' => 'activity', 'id' => $activity->id, 'title' => $activity->title, 'currency' => $activity->currency];
                continue;
            }
            $category = match ($activity->type) {
                'transport' => 'transport',
                'stay' => 'accommodation',
                'activity', 'reservation' => 'activities',
                default => 'other',
            };
            $categories[$category]['planned'] += $amount;
            if (isset($daily[$activity->date])) {
                $daily[$activity->date]['planned'] += $amount;
            }
        }

        // Recepty naplánované do cesty jsou součástí stejného low-cost rozpočtu.
        // Aktivita vytvořená jídelním plánem nemá vlastní cenu, takže se nic nepočítá dvakrát.
        if (Schema::hasTable('planned_meals')) {
            foreach (DB::table('planned_meals as meal')
                ->join('recipes as recipe', 'recipe.id', '=', 'meal.recipe_id')
                ->where('meal.trip_id', $trip->id)
                ->whereNotNull('meal.estimated_cost')
                ->whereIn('meal.status', ['planned', 'prepared'])
                ->get(['meal.id', 'meal.estimated_cost', 'meal.currency', 'meal.planned_for', 'recipe.title']) as $meal) {
                $amount = $this->convert((float) $meal->estimated_cost, $meal->currency, $currency, $meal->planned_for, $rateCache);
                if ($amount === null) {
                    $unconverted[] = ['source' => 'meal', 'id' => $meal->id, 'title' => $meal->title, 'currency' => $meal->currency];
                    continue;
                }
                $categories['food']['planned'] += $amount;
                $date = Carbon::parse($meal->planned_for)->toDateString();
                if (isset($daily[$date])) $daily[$date]['planned'] += $amount;
            }
        }

        $limits = DB::table('trip_budget_limits')->where('trip_id', $trip->id)->get()->keyBy('category');
        $warnings = [];
        foreach ($categories as $category => &$row) {
            $row['planned'] = round($row['planned'], 2);
            $row['actual'] = round($row['actual'], 2);
            $row['total'] = round($row['planned'] + $row['actual'], 2);
            $limit = $limits->get($category);
            if ($limit) {
                $row['limit'] = $this->convert((float) $limit->amount, $limit->currency, $currency, null, $rateCache);
                $warnPercent = (int) $limit->warn_percent;
                if ($row['limit'] !== null && $row['limit'] > 0) {
                    $row['usage_percent'] = round($row['total'] / $row['limit'] * 100, 1);
                    $row['status'] = $row['usage_percent'] >= 100 ? 'over' : ($row['usage_percent'] >= $warnPercent ? 'warning' : 'ok');
                    if ($row['status'] !== 'ok') {
                        $warnings[] = $this->warning(
                            "category_{$row['status']}_{$category}",
                            $row['status'] === 'over' ? 'danger' : 'warning',
                            "{$row['label']}: " . ($row['status'] === 'over' ? 'limit je překročený' : 'blíží se limitu'),
                            "Naplánováno a utraceno " . number_format($row['total'], 0, ',', ' ') . " z " . number_format($row['limit'], 0, ',', ' ') . " {$currency}.",
                            $category,
                        );
                    }
                }
            }
        }
        unset($row);

        foreach ($daily as &$row) {
            $row['planned'] = round($row['planned'], 2);
            $row['actual'] = round($row['actual'], 2);
            $row['total'] = round($row['planned'] + $row['actual'], 2);
            if ($dailyLimit !== null && $dailyLimit > 0) {
                $row['usage_percent'] = round($row['total'] / $dailyLimit * 100, 1);
                $row['status'] = $row['usage_percent'] >= 100 ? 'over' : ($row['usage_percent'] >= 80 ? 'warning' : 'ok');
                if ($row['status'] === 'over') {
                    $warnings[] = $this->warning('day_over_' . $row['date'], 'danger', 'Denní limit je překročený', "Pro {$row['date']} je naplánováno nebo utraceno " . number_format($row['total'], 0, ',', ' ') . " {$currency}.");
                }
            }
        }
        unset($row);

        $planned = round((float) collect($categories)->sum('planned'), 2);
        $actual = round((float) collect($categories)->sum('actual'), 2);
        $projected = round($planned + $actual, 2);
        $usage = $budget !== null && $budget > 0 ? round($projected / $budget * 100, 1) : null;
        if ($usage !== null && $usage >= 100) {
            array_unshift($warnings, $this->warning('total_over', 'danger', 'Celkový rozpočet je překročený', 'Aktuální odhad překračuje rozpočet o ' . number_format($projected - $budget, 0, ',', ' ') . " {$currency}."));
        } elseif ($usage !== null && $usage >= 80) {
            array_unshift($warnings, $this->warning('total_warning', 'warning', 'Rozpočet se blíží limitu', "Je využito {$usage} % společného rozpočtu."));
        }
        if ($unconverted !== []) {
            $warnings[] = $this->warning('currency_missing', 'warning', 'Část nákladů je v jiné měně', 'Doplňte kurz měny, aby součet zahrnul všechny položky.');
        }

        $choices = Schema::hasTable('trip_travel_choices')
            ? DB::table('trip_travel_choices')->where('trip_id', $trip->id)->where('is_selected', true)->get()
            : collect();
        $recommendations = [];
        if ($budget === null) {
            $recommendations[] = $this->recommendation('budget_missing', 'Nastavte společný rozpočet', 'Stačí celková částka nebo denní limit; systém dopočítá druhou hodnotu.');
        }
        if ($limits->count() < count(self::CATEGORIES) && $budget !== null) {
            $recommendations[] = $this->recommendation('limits_missing', 'Rozdělte rozpočet do kategorií', 'Automatický plán zachová ručně nastavené limity a doplní chybějící.');
        }
        if (! $choices->contains('kind', 'transport')) {
            $recommendations[] = $this->recommendation('transport_missing', 'Porovnejte a vyberte dopravu', 'Vybraný spoj se automaticky promítne do trasy i plánovaného rozpočtu.');
        } elseif ($choices->where('kind', 'transport')->contains(fn ($choice) => $choice->amount === null)) {
            $recommendations[] = $this->recommendation('transport_price_missing', 'Doplňte cenu dopravy', 'Bez ceny nelze low-cost variantu spolehlivě porovnat.');
        }
        if (! $choices->contains('kind', 'accommodation')) {
            $recommendations[] = $this->recommendation('accommodation_missing', 'Vyberte ubytování', 'Uložte vybranou nabídku a cenu přímo do cesty.');
        } elseif ($choices->where('kind', 'accommodation')->contains(fn ($choice) => $choice->amount === null)) {
            $recommendations[] = $this->recommendation('accommodation_price_missing', 'Doplňte cenu ubytování', 'Cena pobytu patří do společného odhadu ještě před rezervací.');
        }

        $dangerCount = collect($warnings)->where('severity', 'danger')->count();
        $warningCount = collect($warnings)->where('severity', 'warning')->count();
        $score = max(0, 100 - ($budget === null ? 20 : 0) - min(50, $dangerCount * 20) - min(25, $warningCount * 5) - min(15, $recommendations === [] ? 0 : count($recommendations) * 3));
        $status = $dangerCount > 0 ? 'over' : ($warningCount > 0 || $recommendations !== [] ? 'attention' : 'ready');

        return [
            'profile' => $profile,
            'profile_label' => ['economy' => 'Úsporně', 'balanced' => 'Vyváženě', 'comfort' => 'Pohodlně'][$profile],
            'currency' => $currency,
            'days_count' => $days,
            'budget' => $budget,
            'configured_budget' => $configuredBudget,
            'daily_limit' => $dailyLimit,
            'configured_daily_limit' => $configuredDaily,
            'planned' => $planned,
            'actual' => $actual,
            'projected' => $projected,
            'remaining' => $budget !== null ? round($budget - $projected, 2) : null,
            'usage_percent' => $usage,
            'health_score' => $score,
            'status' => $status,
            'categories' => array_values($categories),
            'days' => array_values($daily),
            'warnings' => $warnings,
            'recommendations' => $recommendations,
            'selected_choices' => $choices->values(),
            'saved_place_suggestions' => $this->placeSuggestions($trip, $profile),
            'unconverted_items' => $unconverted,
        ];
    }

    public function applyDefaultLimits(object $trip, bool $replace = false): int
    {
        $snapshot = $this->snapshot($trip);
        if ($snapshot['budget'] === null) {
            return 0;
        }

        $created = 0;
        foreach (self::SHARES[$snapshot['profile']] as $category => $share) {
            $existing = DB::table('trip_budget_limits')->where('trip_id', $trip->id)->where('category', $category)->exists();
            if ($existing && ! $replace) {
                continue;
            }
            DB::table('trip_budget_limits')->updateOrInsert(
                ['trip_id' => $trip->id, 'category' => $category],
                ['amount' => round($snapshot['budget'] * $share, 2), 'currency' => $snapshot['currency'], 'warn_percent' => 80, 'updated_at' => now(), 'created_at' => now()],
            );
            $created++;
        }

        return $created;
    }

    public function syncCalendarTasks(object $trip, array $snapshot): array
    {
        if (! Schema::hasTable('calendar_events') || ! Schema::hasTable('event_tasks')) {
            return ['event_uuid' => null, 'created' => 0, 'updated' => 0];
        }

        $eventQuery = DB::table('calendar_events')->where('gallery_space_id', $trip->gallery_space_id)->where(function ($query) use ($trip) {
            $query->where('trip_id', $trip->id);
            if (Schema::hasColumn('calendar_events', 'source_trip_id')) {
                $query->orWhere('source_trip_id', $trip->id);
            }
        });
        $event = $eventQuery->orderByDesc('id')->first();
        if (! $event) {
            return ['event_uuid' => null, 'created' => 0, 'updated' => 0];
        }

        $items = collect($snapshot['warnings'])->filter(fn (array $item) => in_array($item['severity'], ['danger', 'warning'], true))
            ->concat($snapshot['recommendations'])
            ->take(8);
        $dueAt = Carbon::parse($trip->start_date)->subWeek()->setTime(18, 0);
        if ($dueAt->isPast()) {
            $dueAt = now()->addHours(2);
        }
        $created = 0;
        $updated = 0;
        $hasAutomationIdentity = Schema::hasColumn('event_tasks', 'automation_source') && Schema::hasColumn('event_tasks', 'automation_key');
        $activeKeys = [];
        foreach ($items as $item) {
            $title = $this->taskTitle($item);
            $automationKey = 'budget_' . $item['code'];
            $activeKeys[] = $automationKey;
            $existingQuery = DB::table('event_tasks')->where('event_id', $event->id);
            $existing = $hasAutomationIdentity
                ? $existingQuery->where('automation_key', $automationKey)->first()
                : $existingQuery->where('title', $title)->first();
            $values = [
                'notes' => ($item['message'] ?? '') . ' Automaticky propojeno s low-cost plánem cesty.',
                'due_at' => $dueAt,
                'priority' => ($item['severity'] ?? null) === 'danger' ? 'high' : 'normal',
                'updated_at' => now(),
            ];
            if ($hasAutomationIdentity) {
                $values['automation_source'] = 'trip_budget';
                $values['automation_key'] = $automationKey;
            }
            if ($existing) {
                DB::table('event_tasks')->where('id', $existing->id)->update($values);
                $updated++;
            } else {
                DB::table('event_tasks')->insert($values + [
                    'event_id' => $event->id,
                    'title' => $title,
                    'sort_order' => ((int) DB::table('event_tasks')->where('event_id', $event->id)->max('sort_order')) + 1,
                    'created_at' => now(),
                ]);
                $created++;
            }
        }

        if ($hasAutomationIdentity) {
            $obsolete = DB::table('event_tasks')->where('event_id', $event->id)->where('automation_source', 'trip_budget')->whereNull('completed_at');
            if ($activeKeys !== []) $obsolete->whereNotIn('automation_key', $activeKeys);
            $obsolete->update(['completed_at' => now(), 'updated_at' => now()]);
        }

        return ['event_uuid' => $event->uuid, 'created' => $created, 'updated' => $updated];
    }

    private function placeSuggestions(object $trip, string $profile): array
    {
        if (! Schema::hasTable('places') || ! Schema::hasColumn('places', 'gallery_space_id') || ! Schema::hasColumn('places', 'price_level')) {
            return [];
        }
        $maxPrice = ['economy' => 1, 'balanced' => 2, 'comfort' => 3][$profile];

        return DB::table('places')
            ->where('gallery_space_id', $trip->gallery_space_id)
            ->whereNotNull('price_level')
            ->where('price_level', '<=', $maxPrice)
            ->orderByDesc('personal_rating')
            ->orderBy('price_level')
            ->limit(5)
            ->get(['id', 'name', 'city', 'country', 'type', 'price_level', 'personal_rating', 'estimated_visit_minutes'])
            ->map(fn ($place) => (array) $place)
            ->all();
    }

    private function convert(float $amount, ?string $from, string $to, mixed $effectiveAt, array &$cache): ?float
    {
        $from = strtoupper($from ?: $to);
        if ($from === $to) {
            return round($amount, 2);
        }
        if (! Schema::hasTable('currency_rates')) {
            return null;
        }
        $date = $effectiveAt ? Carbon::parse($effectiveAt)->toDateString() : now()->toDateString();
        $key = "{$from}:{$to}:{$date}";
        if (! array_key_exists($key, $cache)) {
            $direct = DB::table('currency_rates')->where('base_currency', $from)->where('quote_currency', $to)->where('effective_on', '<=', $date)->orderByDesc('effective_on')->value('rate');
            if ($direct !== null) {
                $cache[$key] = (float) $direct;
            } else {
                $inverse = DB::table('currency_rates')->where('base_currency', $to)->where('quote_currency', $from)->where('effective_on', '<=', $date)->orderByDesc('effective_on')->value('rate');
                $cache[$key] = $inverse ? 1 / (float) $inverse : null;
            }
        }

        return $cache[$key] !== null ? round($amount * $cache[$key], 2) : null;
    }

    private function warning(string $code, string $severity, string $title, string $message, ?string $category = null): array
    {
        return array_filter(compact('code', 'severity', 'title', 'message', 'category'), fn ($value) => $value !== null);
    }

    private function recommendation(string $code, string $title, string $message): array
    {
        return compact('code', 'title', 'message');
    }

    private function taskTitle(array $item): string
    {
        if (isset($item['category'])) {
            return 'Zkontrolovat rozpočet: ' . self::LABELS[$item['category']];
        }

        return match ($item['code']) {
            'total_over', 'total_warning' => 'Zkontrolovat celkový rozpočet cesty',
            'budget_missing' => 'Domluvit společný rozpočet cesty',
            'limits_missing' => 'Rozdělit rozpočet cesty do kategorií',
            'transport_missing', 'transport_price_missing' => 'Vybrat dopravu a uložit cenu',
            'accommodation_missing', 'accommodation_price_missing' => 'Vybrat ubytování a uložit cenu',
            'currency_missing' => 'Doplnit kurz pro cestovní rozpočet',
            default => $item['title'],
        };
    }
}
