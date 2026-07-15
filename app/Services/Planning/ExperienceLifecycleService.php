<?php

namespace App\Services\Planning;

use App\Models\Album;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExperienceLifecycleService
{
    /** Return one permission-neutral progress summary for all existing screens. */
    public function status(CalendarEvent $event, ?User $viewer = null): array
    {
        $plan = $this->placePlan($event);
        $memory = Schema::hasTable('shared_memory_moments')
            ? DB::table('shared_memory_moments')->where('calendar_event_id', $event->id)->first()
            : null;
        $reflectionExists = Schema::hasTable('calendar_event_reflections')
            && DB::table('calendar_event_reflections')->where('calendar_event_id', $event->id)->exists();
        $attachedMediaCount = $event->attachments()->whereNotNull('media_item_id')->count();
        $reviewCount = 0;
        $myReview = false;

        if ($plan && Schema::hasTable('place_reviews')) {
            $reviews = DB::table('place_reviews')->where('place_plan_id', $plan->id)->where('status', 'published');
            $reviewCount = (clone $reviews)->count();
            $myReview = $viewer ? (clone $reviews)->where('author_user_id', $viewer->id)->exists() : false;
        }

        $steps = [
            ['key' => 'attended', 'label' => 'Uskutečněno', 'complete' => $event->status === 'completed' || (bool) $memory],
            ['key' => 'media', 'label' => 'Fotografie a videa', 'complete' => $attachedMediaCount > 0],
            ['key' => 'memory', 'label' => 'Společná vzpomínka', 'complete' => (bool) $memory],
            ['key' => 'reflection', 'label' => 'Společné ohlédnutí', 'complete' => $reflectionExists],
        ];
        if ($plan) $steps[] = ['key' => 'review', 'label' => 'Moje hodnocení podniku', 'complete' => $myReview];
        $completed = collect($steps)->where('complete', true)->count();

        $isFuture = $event->starts_at->isFuture();
        $nextAction = match (true) {
            $isFuture => 'prepare',
            !$memory && $attachedMediaCount === 0 => 'add_media',
            !$memory => 'save_memory',
            $plan && !$myReview => 'review_place',
            !$reflectionExists => 'reflect',
            $attachedMediaCount === 0 => 'add_media',
            default => 'complete',
        };

        return [
            'phase' => $isFuture ? 'planned' : ($completed === count($steps) ? 'remembered' : 'follow_up'),
            'progress_percent' => count($steps) ? (int) round(($completed / count($steps)) * 100) : 0,
            'next_action' => $nextAction,
            'steps' => $steps,
            'attached_media_count' => $attachedMediaCount,
            'reflection_exists' => $reflectionExists,
            'memory' => $memory ? ['uuid' => $memory->uuid, 'title' => $memory->title] : null,
            'place_plan' => $plan ? [
                'uuid' => $plan->uuid,
                'state' => $plan->state,
                'planned_for' => $plan->planned_for,
                'visited_on' => $plan->visited_on,
            ] : null,
            'place' => $plan ? [
                'id' => $plan->place_id,
                'name' => $plan->place_name,
                'type' => $plan->place_type,
                'review_count' => $reviewCount,
                'my_review_complete' => $myReview,
            ] : null,
        ];
    }

    /**
     * Keep the calendar, place visit, gallery media, album and memory in sync
     * after an event is turned into a memory.
     */
    public function complete(CalendarEvent $event, int $memoryId, Album $album, Collection $media, User $actor): array
    {
        return DB::transaction(function () use ($event, $memoryId, $album, $media, $actor) {
            $plan = $this->placePlan($event, true);
            $event->update(['status' => 'completed']);

            if (!$plan) return $this->status($event->fresh(), $actor);

            DB::table('place_plans')->where('id', $plan->id)->update([
                'state' => 'visited',
                'visited_on' => $plan->visited_on ?: $event->starts_at->toDateString(),
                'updated_at' => now(),
            ]);
            if (Schema::hasColumn('shared_memory_moments', 'place_plan_id')) {
                DB::table('shared_memory_moments')->where('id', $memoryId)->update(['place_plan_id' => $plan->id, 'updated_at' => now()]);
            }

            if ($media->isNotEmpty() && Schema::hasTable('media_place')) {
                $rows = $media->map(fn ($item) => [
                    'media_item_id' => $item->id,
                    'place_id' => $plan->place_id,
                    'is_primary' => false,
                    'created_at' => now(),
                ])->all();
                DB::table('media_place')->insertOrIgnore($rows);
            }

            if (Schema::hasTable('album_place')) {
                DB::table('album_place')->insertOrIgnore([
                    'album_id' => $album->id,
                    'place_id' => $plan->place_id,
                    'is_primary' => true,
                    'created_at' => now(),
                ]);
            }
            if (!$album->default_place_id) $album->update(['default_place_id' => $plan->place_id, 'updated_by' => $actor->id]);

            if (Schema::hasTable('calendar_event_reflections')) {
                $nextTime = DB::table('calendar_event_reflections')->where('calendar_event_id', $event->id)->value('next_time');
                if ($nextTime) DB::table('places')->where('id', $plan->place_id)->whereNull('next_time_note')->update(['next_time_note' => $nextTime, 'updated_at' => now()]);
            }

            return $this->status($event->fresh(), $actor);
        });
    }

    /** Find the newest unfinished follow-up for the dashboard. */
    public function pendingFollowUp(GallerySpace $space, User $viewer): ?array
    {
        $events = CalendarEvent::query()
            ->where('gallery_space_id', $space->id)
            ->where('starts_at', '<=', now())
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('starts_at')
            ->limit(20)
            ->get();

        foreach ($events as $event) {
            $status = $this->status($event, $viewer);
            if ($status['next_action'] === 'complete') continue;
            return [
                'uuid' => $event->uuid,
                'title' => $event->title,
                'starts_at' => $event->starts_at->toIso8601String(),
                'progress_percent' => $status['progress_percent'],
                'next_action' => $status['next_action'],
                'place' => $status['place'],
            ];
        }

        return null;
    }

    private function placePlan(CalendarEvent $event, bool $lock = false): ?object
    {
        if (!Schema::hasTable('place_plans') || !Schema::hasTable('places')) return null;
        $query = DB::table('place_plans as plan')
            ->join('places as place', 'place.id', '=', 'plan.place_id')
            ->where('plan.calendar_event_id', $event->id)
            ->where('plan.gallery_space_id', $event->gallery_space_id)
            ->select([
                'plan.id', 'plan.uuid', 'plan.state', 'plan.planned_for', 'plan.visited_on', 'plan.place_id',
                'place.name as place_name', 'place.type as place_type',
            ]);
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }
}
