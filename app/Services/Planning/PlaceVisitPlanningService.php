<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlaceVisitPlanningService
{
    /**
     * Create one shared place plan, calendar event, participants and reminders.
     * Repeating the same request for the same start is idempotent.
     */
    public function schedule(GallerySpace $space, User $user, Place $place, array $data): array
    {
        $startsAt = Carbon::parse($data['starts_at'] ?? (($data['planned_for'] ?? now()->addWeek()->toDateString()) . ' 10:00:00'));
        $duration = (int) ($data['duration_minutes'] ?? $place->estimated_visit_minutes ?? 120);
        $reminderMinutes = (int) ($data['reminder_minutes'] ?? 1440);

        return DB::transaction(function () use ($space, $user, $place, $data, $startsAt, $duration, $reminderMinutes) {
            $existing = DB::table('place_plans as plan')
                ->join('calendar_events as event', 'event.id', '=', 'plan.calendar_event_id')
                ->where('plan.gallery_space_id', $space->id)
                ->where('plan.place_id', $place->id)
                ->where('plan.state', 'planned')
                ->where('event.starts_at', $startsAt->format('Y-m-d H:i:s'))
                ->select('plan.*')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['plan' => $existing, 'event' => CalendarEvent::findOrFail($existing->calendar_event_id), 'created' => false];
            }

            $recommendationReason = $data['recommendation_reason'] ?? null;
            $recommendedItem = $data['recommended_item'] ?? null;
            $description = $data['notes'] ?? $place->next_time_note ?? $place->description;
            if ($recommendedItem && !$description) $description = "Příště si dát: {$recommendedItem}.";

            $event = CalendarEvent::create([
                'gallery_space_id' => $space->id,
                'created_by' => $user->id,
                'title' => !empty($data['from_recommendation']) ? "Rande · {$place->name}" : $place->name,
                'description' => $description,
                'type' => 'outing',
                'status' => 'planned',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($duration),
                'timezone' => 'Europe/Prague',
                'place_name' => $place->name,
                'latitude' => $place->latitude,
                'longitude' => $place->longitude,
                'color' => '#f97316',
                'metadata' => array_filter([
                    'source' => !empty($data['from_recommendation']) ? 'couple_experience_recommendation' : 'saved_place',
                    'kind' => !empty($data['from_recommendation']) ? 'place_recommendation_outing' : 'saved_place_outing',
                    'place_id' => $place->id,
                    'recommendation_reason' => $recommendationReason,
                    'recommended_item' => $recommendedItem,
                ], fn ($value) => $value !== null && $value !== ''),
            ]);

            $memberIds = $space->members()->pluck('users.id')->push($user->id)->unique()->values();
            $event->participants()->sync($memberIds->mapWithKeys(fn (int $id) => [$id => [
                'role' => $id === (int) $user->id ? 'owner' : 'editor',
                'response' => $id === (int) $user->id ? 'accepted' : 'pending',
            ]])->all());

            foreach ($memberIds as $memberId) {
                $remindAt = $startsAt->copy()->subMinutes($reminderMinutes);
                if ($remindAt->isFuture()) {
                    $event->reminders()->create(['user_id' => $memberId, 'channel' => 'database', 'remind_at' => $remindAt, 'status' => 'pending']);
                }
            }

            if (!empty($data['reservation_reference']) || !empty($data['reservation_url'])) {
                $event->attachments()->create([
                    'kind' => 'reservation',
                    'label' => 'Rezervace · ' . $place->name,
                    'reference_code' => $data['reservation_reference'] ?? null,
                    'external_url' => $data['reservation_url'] ?? null,
                ]);
            }

            $id = DB::table('place_plans')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'place_id' => $place->id,
                'gallery_space_id' => $space->id,
                'created_by' => $user->id,
                'calendar_event_id' => $event->id,
                'state' => 'planned',
                'planned_for' => $startsAt->toDateString(),
                'reservation_reference' => $data['reservation_reference'] ?? null,
                'reservation_url' => $data['reservation_url'] ?? null,
                'notes' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['plan' => DB::table('place_plans')->find($id), 'event' => $event, 'created' => true];
        });
    }
}
