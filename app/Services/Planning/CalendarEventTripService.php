<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Keeps a calendar event and its trip workspace as one shared planning object. */
class CalendarEventTripService
{
    /** @return array{0: object, 1: bool} */
    public function createFromEvent(CalendarEvent $event, int $actorId): array
    {
        if ($event->trip_id) {
            return [DB::table('trips')->find($event->trip_id), false];
        }

        return DB::transaction(function () use ($event, $actorId) {
            $end = $event->ends_at ?? $event->starts_at;
            $tripId = DB::table('trips')->insertGetId([
                'gallery_space_id' => $event->gallery_space_id,
                'created_by' => $actorId,
                'name' => $event->title,
                'description' => $event->description,
                'start_date' => $event->starts_at->toDateString(),
                'end_date' => $end->toDateString(),
                'notes' => $event->place_name
                    ? "Vzniklo z kalendářové akce · {$event->place_name}"
                    : 'Vzniklo z kalendářové akce',
                'status' => 'planned',
                'timezone' => $event->timezone ?? 'Europe/Prague',
                'currency' => 'CZK',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $event->update(['trip_id' => $tripId, 'type' => 'trip']);

            if ($event->place_name) {
                DB::table('trip_waypoints')->insert([
                    'trip_id' => $tripId,
                    'place_name' => $event->place_name,
                    'latitude' => $event->latitude,
                    'longitude' => $event->longitude,
                    'sort_order' => 0,
                    'arrived_at' => $event->starts_at->toDateString(),
                    'departed_at' => $end->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->createDays($tripId, $event->starts_at, $end);
            $this->createPreparationTasks($event);

            return [DB::table('trips')->find($tripId), true];
        });
    }

    private function createDays(int $tripId, Carbon $start, Carbon $end): void
    {
        $rows = [];
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        for ($order = 0; $cursor->lte($last) && $order < 366; $order++, $cursor->addDay()) {
            $rows[] = [
                'trip_id' => $tripId,
                'date' => $cursor->toDateString(),
                'title' => 'Den ' . ($order + 1),
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows) {
            DB::table('trip_days')->insert($rows);
        }
    }

    private function createPreparationTasks(CalendarEvent $event): void
    {
        foreach ([
            ['Domluvit rozpočet a kdo co zaplatí', 14, 'high'],
            ['Zkontrolovat doklady a rezervace', 7, 'high'],
            ['Dokončit balení na cestu', 2, 'normal'],
        ] as $order => [$title, $daysBefore, $priority]) {
            DB::table('event_tasks')->insert([
                'event_id' => $event->id,
                'title' => $title,
                'due_at' => $event->starts_at->copy()->subDays($daysBefore),
                'priority' => $priority,
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
