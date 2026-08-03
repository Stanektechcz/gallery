<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use Illuminate\Support\Facades\DB;

/** Keeps elapsed plans out of active calendar, weekly and dashboard views. */
class CalendarEventLifecycleService
{
    public function completeElapsedPlans(array $spaceIds): int
    {
        $spaceIds = array_values(array_unique(array_filter($spaceIds)));
        if ($spaceIds === []) return 0;

        $events = CalendarEvent::query()
            ->whereIn('gallery_space_id', $spaceIds)
            ->whereIn('status', ['planned', 'confirmed'])
            ->whereRaw('COALESCE(ends_at, starts_at) < ?', [now()->startOfDay()])
            ->get();

        $completed = 0;
        foreach ($events as $event) {
            if (($event->metadata['keep_open_after_end'] ?? false) === true) continue;
            DB::transaction(function () use ($event): void {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $metadata['auto_completed_at'] = now('Europe/Prague')->toIso8601String();
                $metadata['auto_completed_reason'] = 'Termín skončil před dnešním dnem.';
                $event->update(['status' => 'completed', 'metadata' => $metadata]);
                $event->tasks()->whereNull('completed_at')->update(['completed_at' => now(), 'updated_at' => now()]);
                $event->reminders()->where('status', 'pending')->update(['status' => 'dismissed', 'dismissed_at' => now(), 'updated_at' => now()]);
            });
            $completed++;
        }

        return $completed;
    }
}