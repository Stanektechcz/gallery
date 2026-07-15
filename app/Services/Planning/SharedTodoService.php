<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\SharedTodoList;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SharedTodoService
{
    public function ensureDefaultList(GallerySpace $space, User $user): SharedTodoList
    {
        return SharedTodoList::firstOrCreate(
            ['gallery_space_id' => $space->id, 'kind' => 'general', 'title' => 'Společné úkoly'],
            ['created_by' => $user->id, 'description' => 'Věci, které chceme zařídit spolu.', 'color' => '#14b8a6', 'icon' => '✅']
        );
    }

    public function complete(SharedTodo $todo, User $user, bool $completed): ?SharedTodo
    {
        $todo->update([
            'status' => $completed ? 'completed' : 'open',
            'completed_at' => $completed ? now() : null,
            'completed_by' => $completed ? $user->id : null,
        ]);
        if (! $completed || empty($todo->recurrence)) return null;
        if (SharedTodo::where('series_uuid', $todo->series_uuid)->where('id', '!=', $todo->id)->where('status', 'open')->exists()) return null;
        $nextDue = $this->nextOccurrence($todo->due_at ?: $todo->starts_at ?: now(), $todo->recurrence);
        if (! $nextDue) return null;
        $duration = $todo->starts_at && $todo->due_at ? $todo->starts_at->diffInMinutes($todo->due_at, false) : null;
        $attributes = $todo->only(['series_uuid', 'gallery_space_id', 'list_id', 'created_by', 'assigned_to', 'trip_id', 'title', 'description', 'priority', 'estimate_minutes', 'location', 'recurrence', 'tags', 'metadata', 'sort_order']);
        $attributes['parent_id'] = $todo->parent_id;
        $attributes['status'] = 'open';
        $attributes['due_at'] = $nextDue;
        if ($todo->starts_at) $attributes['starts_at'] = $duration !== null ? $nextDue->copy()->subMinutes($duration) : $nextDue;
        if ($todo->remind_at && $todo->due_at) $attributes['remind_at'] = $nextDue->copy()->subSeconds($todo->due_at->diffInSeconds($todo->remind_at));
        return SharedTodo::create($attributes);
    }

    public function schedule(SharedTodo $todo, User $actor, ?Carbon $startsAt = null): CalendarEvent
    {
        if ($todo->calendar_event_id) return CalendarEvent::findOrFail($todo->calendar_event_id);
        $startsAt ??= $todo->starts_at ?: $todo->due_at ?: now()->addDay()->setTime(18, 0);
        $duration = max(15, (int) ($todo->estimate_minutes ?: 60));
        return DB::transaction(function () use ($todo, $actor, $startsAt, $duration) {
            $event = CalendarEvent::create([
                'gallery_space_id' => $todo->gallery_space_id, 'created_by' => $actor->id, 'trip_id' => $todo->trip_id,
                'title' => 'Úkol · ' . $todo->title, 'description' => $todo->description,
                'type' => 'todo', 'status' => 'planned', 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes($duration),
                'timezone' => 'Europe/Prague', 'place_name' => $todo->location, 'color' => '#14b8a6', 'is_private' => false,
                'metadata' => ['kind' => 'shared_todo', 'todo_uuid' => $todo->uuid, 'href' => '/planning#todos'],
            ]);
            foreach (DB::table('gallery_space_user')->where('gallery_space_id', $todo->gallery_space_id)->pluck('user_id') as $memberId) {
                DB::table('event_participants')->insertOrIgnore([
                    'event_id' => $event->id, 'user_id' => $memberId, 'role' => (int) $memberId === $actor->id ? 'organizer' : 'guest',
                    'response' => (int) $memberId === $actor->id ? 'accepted' : 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($todo->remind_at && $todo->remind_at->isFuture()) {
                DB::table('event_reminders')->insertOrIgnore(['event_id' => $event->id, 'user_id' => $todo->assigned_to ?: $actor->id, 'channel' => 'database', 'remind_at' => $todo->remind_at, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
            $todo->update(['calendar_event_id' => $event->id, 'starts_at' => $startsAt]);
            return $event;
        });
    }

    public function nextOccurrence(Carbon|string $from, array $recurrence): ?Carbon
    {
        $date = Carbon::parse($from);
        $interval = max(1, min(365, (int) ($recurrence['interval'] ?? 1)));
        $next = match ($recurrence['frequency'] ?? null) {
            'daily' => $date->copy()->addDays($interval),
            'weekly' => $date->copy()->addWeeks($interval),
            'monthly' => $date->copy()->addMonthsNoOverflow($interval),
            'yearly' => $date->copy()->addYearsNoOverflow($interval),
            default => null,
        };
        if (! $next) return null;
        if (! empty($recurrence['until']) && $next->gt(Carbon::parse($recurrence['until'])->endOfDay())) return null;
        return $next;
    }
}
