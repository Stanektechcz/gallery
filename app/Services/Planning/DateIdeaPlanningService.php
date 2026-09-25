<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\User;
use App\Notifications\GalleryNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DateIdeaPlanningService
{
    public function __construct(
        private readonly CalendarEventTripService $tripService,
        private readonly CalendarEventCreationService $calendarEvents,
        private readonly DateIdeaTripSyncService $tripSync,
    ) {}

    public function plan(CoupleDateIdea $idea, User $actor, array $options = []): CalendarEvent
    {
        if ($idea->calendar_event_id) {
            $event = $idea->event()->firstOrFail();
            $shouldCreateTrip = (bool) ($options['create_trip'] ?? ($idea->plan['is_trip_recommended'] ?? false));
            if ($shouldCreateTrip && ! $event->trip_id) {
                [$trip] = $this->tripService->createFromEvent($event, $actor->id);
                $event->refresh();
                $idea->update(['trip_id' => $trip->id, 'status' => 'planned']);
            }
            if ($event->trip_id) {
                $this->tripSync->sync($idea->fresh(), $event, (int) $event->trip_id, $actor);
            }

            return $event->fresh();
        }

        // Druhý požadavek dvojkliku najde pod zámkem hotovou akci — upozornění
        // pak nechodí podruhé.
        $created = false;
        $event = DB::transaction(function () use ($idea, $actor, $options, &$created) {
            $locked = CoupleDateIdea::query()->lockForUpdate()->findOrFail($idea->id);
            if ($locked->calendar_event_id) {
                return CalendarEvent::findOrFail($locked->calendar_event_id);
            }

            $start = Carbon::parse($options['starts_at'] ?? $locked->suggested_starts_at ?? now()->addDay()->setTime(18, 0));
            $end = $start->copy()->addMinutes($locked->estimated_minutes);
            $destination = $locked->destination ?? [];
            $plan = $locked->plan ?? [];
            $createTrip = (bool) ($options['create_trip'] ?? ($plan['is_trip_recommended'] ?? false));
            $timeline = collect($plan['blocks'] ?? [])->map(fn (array $block) => ($block['icon'] ?? '•').' '.($block['title'] ?? 'Zastávka'))->join(' → ');

            $event = $this->calendarEvents->create($locked->space()->firstOrFail(), $actor, [
                'created_by' => $actor->id,
                'title' => $locked->title,
                'description' => trim($locked->summary."\n\nProgram: {$timeline}\n\nOdhad pro dva: ".number_format($locked->estimated_cost, 0, ',', ' ')." {$locked->currency}."),
                'type' => $createTrip ? 'trip' : 'outing',
                'status' => 'planned',
                'starts_at' => $start,
                'ends_at' => $end,
                'all_day' => $locked->estimated_minutes >= 480,
                'timezone' => 'Europe/Prague',
                'place_name' => $destination['location_name'] ?? null,
                'latitude' => $destination['latitude'] ?? null,
                'longitude' => $destination['longitude'] ?? null,
                'color' => '#ec4899',
                'is_private' => false,
                'metadata' => [
                    'kind' => 'generated_date_idea',
                    'date_idea_uuid' => $locked->uuid,
                    'theme' => $locked->theme,
                    'estimated_cost' => $locked->estimated_cost,
                    'currency' => $locked->currency,
                    'is_cost_estimate' => true,
                    'date_plan' => $plan,
                ],
            ]);

            // Účastníky (dvojici, bez hostů galerie) už zapsal `CalendarEventCreationService`.
            // Dřív se tu znovu brali všichni členové prostoru — host tak dostal
            // pozvánku, připomínku i upozornění na randíčko dvojice.
            $reminderMinutes = (int) ($options['reminder_minutes'] ?? ($createTrip ? 1440 : 180));

            foreach ($event->participants()->pluck('users.id') as $memberId) {
                $event->reminders()->create([
                    'user_id' => $memberId,
                    'channel' => 'database',
                    'remind_at' => $start->copy()->subMinutes($reminderMinutes)->max(now()->addMinute()),
                    'status' => 'pending',
                ]);
            }

            foreach (collect($plan['preparation_tasks'] ?? [])->take(8)->values() as $order => $title) {
                $event->tasks()->create([
                    'title' => $title,
                    'due_at' => $start->copy()->subDay()->max(now()),
                    'priority' => $order < 2 ? 'high' : 'normal',
                    'sort_order' => $order,
                ]);
            }

            $locked->update(['calendar_event_id' => $event->id, 'status' => 'planned']);
            $created = true;

            return $event;
        });

        $shouldCreateTrip = (bool) ($options['create_trip'] ?? ($idea->plan['is_trip_recommended'] ?? false));
        if ($shouldCreateTrip && ! $event->trip_id) {
            [$trip] = $this->tripService->createFromEvent($event, $actor->id);
            $event->refresh();
            $idea->update(['calendar_event_id' => $event->id, 'trip_id' => $trip->id, 'status' => 'planned']);
        }
        if ($event->trip_id) {
            $this->tripSync->sync($idea->fresh(), $event, (int) $event->trip_id, $actor);
        }

        if ($created) {
            $event->participants()->whereKeyNot($actor->id)->get()->each(function (User $participant) use ($actor, $event) {
                $participant->notify(new GalleryNotification('date_idea.planned', $actor->name.' naplánoval/a nové randíčko: '.$event->title, '/calendar/events/'.$event->uuid));
            });
        }

        return $event->fresh();
    }
}
