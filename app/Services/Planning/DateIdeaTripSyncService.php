<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Expands one generated date into the existing, shared trip workspace. */
class DateIdeaTripSyncService
{
    private const SOURCE = 'generated_date_idea';

    public function __construct(private readonly TripPreparationTimelineService $preparation) {}

    public function syncForEvent(CalendarEvent $event, int $tripId, User $actor): ?array
    {
        $metadata = $event->metadata ?? [];
        $idea = CoupleDateIdea::query()
            ->where('gallery_space_id', $event->gallery_space_id)
            ->where(function ($query) use ($event, $metadata) {
                $query->where('calendar_event_id', $event->id);
                if (! empty($metadata['date_idea_uuid'])) $query->orWhere('uuid', $metadata['date_idea_uuid']);
            })->first();

        return $idea ? $this->sync($idea, $event, $tripId, $actor) : null;
    }

    public function sync(CoupleDateIdea $idea, CalendarEvent $event, int $tripId, User $actor): array
    {
        $trip = DB::table('trips')->where('id', $tripId)->where('gallery_space_id', $idea->gallery_space_id)->first();
        if (! $trip || (int) $event->gallery_space_id !== (int) $idea->gallery_space_id) {
            throw new \InvalidArgumentException('Randíčko a cesta musí patřit do stejného partnerského prostoru.');
        }

        if (! $this->schemaReady()) {
            return $this->storeResult($idea, [
                'status' => 'migration_required', 'trip_id' => $tripId,
                'activities' => 0, 'expenses' => 0, 'routes' => 0, 'waypoints' => 0, 'packing_items' => 0,
                'message' => 'Dokončete migrace pro synchronizaci itineráře a rozpočtu.',
            ]);
        }

        $result = DB::transaction(function () use ($idea, $event, $trip, $actor) {
            $days = $this->ensureDays($trip);
            $blocks = collect($idea->plan['blocks'] ?? [])->filter(fn ($block) => is_array($block))->values();
            $dayCount = max(1, $days->count());
            $dayCursors = [];
            $created = ['activities' => 0, 'expenses' => 0, 'routes' => 0, 'waypoints' => 0, 'packing_items' => 0];

            foreach ($days as $dayIndex => $day) {
                $date = Carbon::parse($day->date, $trip->timezone ?: 'Europe/Prague');
                $dayCursors[$day->id] = $date->isSameDay($event->starts_at)
                    ? $event->starts_at->copy()->setTimezone($trip->timezone ?: 'Europe/Prague')
                    : $date->setTime(10, 0);
            }

            foreach ($blocks as $index => $block) {
                $dayIndex = $dayCount === 1 ? 0 : min($dayCount - 1, (int) floor($index * $dayCount / max(1, $blocks->count())));
                $day = $days[$dayIndex];
                $minutes = max(10, min(720, (int) ($block['minutes'] ?? 60)));
                $startsAt = $dayCursors[$day->id];
                $endsAt = $startsAt->copy()->addMinutes($minutes);
                if (! $endsAt->isSameDay($startsAt)) $endsAt = $startsAt->copy()->endOfDay();
                $automationKey = $this->key($idea, 'activity', ($block['key'] ?? 'block').':'.$index);

                $existingActivity = DB::table('trip_activities as activity')
                    ->join('trip_days as day', 'day.id', '=', 'activity.trip_day_id')
                    ->where('day.trip_id', $trip->id)->where('activity.automation_source', self::SOURCE)
                    ->where('activity.automation_key', $automationKey)->exists();
                if (! $existingActivity) {
                    DB::table('trip_activities')->insert([
                        'trip_day_id' => $day->id, 'created_by' => $actor->id, 'type' => 'activity',
                        'title' => (string) ($block['title'] ?? 'Společná zastávka'),
                        'description' => $block['description'] ?? null,
                        'starts_at' => $startsAt->format('H:i:s'), 'ends_at' => $endsAt->format('H:i:s'),
                        'place_name' => ($block['stage'] ?? null) === 'place' ? ($block['title'] ?? null) : null,
                        'latitude' => $block['latitude'] ?? null, 'longitude' => $block['longitude'] ?? null,
                        'status' => 'planned', 'cost' => (float) ($block['estimated_cost'] ?? 0),
                        'currency' => $idea->currency, 'sort_order' => (int) $index,
                        'metadata' => json_encode([
                            'source' => self::SOURCE, 'date_idea_uuid' => $idea->uuid,
                            'date_block_key' => $block['key'] ?? null, 'icon' => $block['icon'] ?? null,
                            'is_cost_estimate' => true,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'automation_source' => self::SOURCE, 'automation_key' => $automationKey,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $created['activities']++;
                }

                $cost = round((float) ($block['estimated_cost'] ?? 0), 2);
                if ($cost > 0 && $this->insertExpense($trip, $event, $idea, $actor, $block, $index, $startsAt, $cost)) {
                    $created['expenses']++;
                }

                if (($block['stage'] ?? null) === 'place' && ! empty($block['title']) && $this->insertWaypoint($trip, $idea, $block, $day, $index)) {
                    $created['waypoints']++;
                }

                $dayCursors[$day->id] = $endsAt->copy()->addMinutes(15);
            }

            $transportCost = round((float) data_get($idea->plan, 'budget.transport', 0), 2);
            if ($transportCost > 0 && $this->insertTransportExpense($trip, $event, $idea, $actor, $transportCost)) {
                $created['expenses']++;
            }
            if ($this->insertRoute($trip, $event, $idea, $actor)) $created['routes']++;
            $created['packing_items'] = $this->insertPacking($trip, $idea, $actor);

            if ($trip->budget === null) {
                $updates = [
                    'budget' => round((float) $idea->estimated_cost, 2), 'currency' => $idea->currency,
                    'budget_profile' => $idea->theme === 'low_cost' ? 'lowcost' : 'balanced', 'updated_at' => now(),
                ];
                if (Schema::hasColumn('trips', 'daily_budget_limit')) {
                    $updates['daily_budget_limit'] = round((float) $idea->estimated_cost / $dayCount, 2);
                }
                DB::table('trips')->where('id', $trip->id)->whereNull('budget')->update($updates);
            }

            $totals = [
                'activities' => DB::table('trip_activities as activity')->join('trip_days as day', 'day.id', '=', 'activity.trip_day_id')
                    ->where('day.trip_id', $trip->id)->where('activity.automation_source', self::SOURCE)->count(),
                'expenses' => DB::table('trip_expenses')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->count(),
                'routes' => DB::table('trip_route_variants')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->count(),
                'waypoints' => DB::table('trip_waypoints')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->count(),
                'packing_items' => DB::table('trip_packing_items')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->count(),
            ];

            return ['status' => 'synced', 'trip_id' => (int) $trip->id] + $totals + ['created' => $created];
        });

        try {
            $result['preparation'] = $this->preparation->sync(DB::table('trips')->find($tripId));
        } catch (\Throwable $exception) {
            report($exception);
            $result['preparation'] = ['status' => 'deferred'];
        }

        return $this->storeResult($idea, $result);
    }

    private function ensureDays(object $trip): \Illuminate\Support\Collection
    {
        $days = DB::table('trip_days')->where('trip_id', $trip->id)->orderBy('sort_order')->get();
        if ($days->isNotEmpty()) return $days;

        $start = Carbon::parse($trip->start_date);
        $end = Carbon::parse($trip->end_date);
        for ($date = $start->copy(), $order = 0; $date->lte($end) && $order < 366; $date->addDay(), $order++) {
            DB::table('trip_days')->insertOrIgnore([
                'trip_id' => $trip->id, 'date' => $date->toDateString(), 'title' => 'Den '.($order + 1),
                'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return DB::table('trip_days')->where('trip_id', $trip->id)->orderBy('sort_order')->get();
    }

    private function insertExpense(object $trip, CalendarEvent $event, CoupleDateIdea $idea, User $actor, array $block, int $index, Carbon $at, float $cost): bool
    {
        $key = $this->key($idea, 'expense', ($block['key'] ?? 'block').':'.$index);
        if (DB::table('trip_expenses')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->where('automation_key', $key)->exists()) return false;

        $category = $this->expenseCategory($block);
        DB::table('trip_expenses')->insert([
            'trip_id' => $trip->id, 'event_id' => $event->id, 'created_by' => $actor->id,
            'title' => 'Odhad · '.($block['title'] ?? 'aktivita'), 'category' => $category,
            'amount' => $cost, 'currency' => $idea->currency, 'state' => 'planned', 'occurred_at' => $at,
            'automation_source' => self::SOURCE, 'automation_key' => $key,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    private function insertTransportExpense(object $trip, CalendarEvent $event, CoupleDateIdea $idea, User $actor, float $cost): bool
    {
        $key = $this->key($idea, 'expense', 'transport');
        if (DB::table('trip_expenses')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->where('automation_key', $key)->exists()) return false;
        DB::table('trip_expenses')->insert([
            'trip_id' => $trip->id, 'event_id' => $event->id, 'created_by' => $actor->id,
            'title' => 'Odhad dopravy · '.$this->transportLabel($idea->transport_mode), 'category' => 'transport',
            'amount' => $cost, 'currency' => $idea->currency, 'state' => 'planned', 'occurred_at' => $event->starts_at,
            'automation_source' => self::SOURCE, 'automation_key' => $key,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    private function insertRoute(object $trip, CalendarEvent $event, CoupleDateIdea $idea, User $actor): bool
    {
        $key = $this->key($idea, 'route', 'primary');
        if (DB::table('trip_route_variants')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->where('automation_key', $key)->exists()) return false;
        $hasSelected = DB::table('trip_route_variants')->where('trip_id', $trip->id)->where('is_selected', true)->exists();
        $destination = $idea->destination ?? [];
        $travelMinutes = (int) data_get($idea->plan, 'route.estimated_travel_minutes', 0);
        DB::table('trip_route_variants')->insert([
            'trip_id' => $trip->id, 'created_by' => $actor->id,
            'title' => 'Doprava pro randíčko · '.$this->transportLabel($idea->transport_mode),
            'strategy' => $idea->theme === 'low_cost' ? 'budget' : 'balanced',
            'transport_modes' => json_encode([$idea->transport_mode]),
            'estimated_minutes' => $travelMinutes ?: null,
            'estimated_cost' => (float) data_get($idea->plan, 'budget.transport', 0),
            'currency' => $idea->currency, 'is_selected' => ! $hasSelected,
            'data' => json_encode([
                'source' => self::SOURCE, 'date_idea_uuid' => $idea->uuid, 'is_estimate' => true,
                'scope' => data_get($idea->plan, 'route.scope'), 'radius_km' => data_get($idea->plan, 'route.radius_km'),
                'destination' => $destination,
                'departure' => $event->starts_at->toIso8601String(),
                'arrival' => $event->starts_at->copy()->addMinutes($travelMinutes)->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'automation_source' => self::SOURCE, 'automation_key' => $key,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    private function insertWaypoint(object $trip, CoupleDateIdea $idea, array $block, object $day, int $index): bool
    {
        $key = $this->key($idea, 'waypoint', ($block['key'] ?? 'place').':'.$index);
        if (DB::table('trip_waypoints')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->where('automation_key', $key)->exists()) return false;

        $duplicate = DB::table('trip_waypoints')->where('trip_id', $trip->id)->where('place_name', $block['title'])
            ->when(isset($block['latitude']), fn ($query) => $query->where('latitude', $block['latitude']))
            ->when(isset($block['longitude']), fn ($query) => $query->where('longitude', $block['longitude']))->exists();
        if ($duplicate) return false;

        DB::table('trip_waypoints')->insert([
            'trip_id' => $trip->id, 'place_name' => $block['title'],
            'latitude' => $block['latitude'] ?? null, 'longitude' => $block['longitude'] ?? null,
            'sort_order' => ((int) DB::table('trip_waypoints')->where('trip_id', $trip->id)->max('sort_order')) + 1,
            'notes' => 'Součást vygenerovaného randíčka · '.($block['description'] ?? ''),
            'arrived_at' => $day->date, 'departed_at' => $day->date,
            'automation_source' => self::SOURCE, 'automation_key' => $key,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    private function insertPacking(object $trip, CoupleDateIdea $idea, User $actor): int
    {
        $blockKeys = collect($idea->plan['blocks'] ?? [])->pluck('key')->filter()->all();
        $items = [];
        if (in_array($idea->travel_scope, ['day_trip', 'weekend'], true)) $items['water'] = ['Láhev s vodou', 'food', true];
        if (in_array($idea->transport_mode, ['transit', 'train'], true)) $items['tickets'] = ['Jízdenky a potvrzení rezervací', 'documents', true];
        if (collect($blockKeys)->intersect(['photo_mission', 'memory_pick'])->isNotEmpty()) $items['camera'] = ['Nabitý telefon nebo fotoaparát', 'electronics', true];
        if (collect($blockKeys)->intersect(['picnic_story', 'indoor_picnic'])->isNotEmpty()) $items['picnic'] = ['Deka a připravené občerstvení', 'food', true];
        if ((bool) data_get($idea->plan, 'weather.rain_expected', false)) $items['rain'] = ['Deštník nebo nepromokavá vrstva', 'clothing', true];
        if ($idea->travel_scope === 'weekend') $items['overnight'] = ['Věci na přespání a nabíječky', 'clothing', true];

        $created = 0;
        foreach ($items as $slug => [$title, $category, $essential]) {
            $key = $this->key($idea, 'packing', $slug);
            if (DB::table('trip_packing_items')->where('trip_id', $trip->id)->where('automation_source', self::SOURCE)->where('automation_key', $key)->exists()) continue;
            DB::table('trip_packing_items')->insert([
                'uuid' => (string) Str::uuid(), 'trip_id' => $trip->id, 'created_by' => $actor->id,
                'title' => $title, 'category' => $category, 'quantity' => 1, 'is_essential' => $essential,
                'is_packed' => false, 'source_template' => 'date_idea',
                'sort_order' => ((int) DB::table('trip_packing_items')->where('trip_id', $trip->id)->max('sort_order')) + 1,
                'automation_source' => self::SOURCE, 'automation_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $created++;
        }
        return $created;
    }

    private function storeResult(CoupleDateIdea $idea, array $result): array
    {
        $result['synced_at'] = now()->toIso8601String();
        $plan = $idea->fresh()->plan ?? [];
        $plan['trip_sync'] = $result;
        $idea->update(['plan' => $plan, 'trip_id' => $result['trip_id']]);
        return $result;
    }

    private function expenseCategory(array $block): string
    {
        $key = Str::lower((string) ($block['key'] ?? '').' '.(string) ($block['title'] ?? ''));
        if (Str::contains($key, ['food', 'taste', 'dessert', 'picnic', 'recept', 'ochut'])) return 'food';
        if (Str::contains($key, ['culture', 'galer', 'muze', 'výstav'])) return 'tickets';
        return 'activities';
    }

    private function transportLabel(string $mode): string
    {
        return match ($mode) {
            'walk' => 'pěšky', 'bike' => 'na kole', 'transit' => 'MHD / autobusem',
            'car' => 'autem', 'train' => 'vlakem', default => $mode,
        };
    }

    private function key(CoupleDateIdea $idea, string $kind, string $suffix): string
    {
        return hash('sha256', self::SOURCE.':'.$idea->uuid.':'.$kind.':'.$suffix);
    }

    private function schemaReady(): bool
    {
        foreach (['trip_activities', 'trip_expenses', 'trip_route_variants', 'trip_waypoints', 'trip_packing_items'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'automation_key')) return false;
        }
        return Schema::hasTable('trip_days');
    }
}
