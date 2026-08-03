<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Services\Planning\CalendarEventLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request, CalendarEventLifecycleService $eventLifecycle): Response
    {
        $user = $request->user();
        $space = $user->gallerySpaces()->orderByDesc('is_default')->firstOrFail();
        $eventLifecycle->completeElapsedPlans([$space->id]);

        $media = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('primary_album_id')
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->whereIn('status', ['ready', 'received'])
            ->with(['variants' => fn ($query) => $query->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('uploaded_at')
            ->paginate(60);

        return Inertia::render('Inbox/Index', [
            'media' => $media,
            'actionItems' => $this->actionItems($space->id, $user->id),
            'recentLifeEvents' => $this->recentLifeEvents($space->id),
        ]);
    }

    private function recentLifeEvents(int $spaceId): array
    {
        if (! Schema::hasTable('life_events')) return [];

        return DB::table('life_events')
            ->where('gallery_space_id', $spaceId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['uuid', 'kind', 'title', 'source', 'subject_type', 'subject_id', 'occurred_at'])
            ->map(function ($event): array {
                $href = match ($event->subject_type) {
                    'App\\Models\\CalendarEvent' => '/calendar',
                    'App\\Models\\EntertainmentTitle' => '/watchlist',
                    'App\\Models\\Recipe' => '/recipes',
                    'App\\Models\\SharedTodo' => '/planning',
                    'App\\Models\\Trip', 'trip' => '/trips/' . $event->subject_id . '/plan',
                    'shared_expense' => '/finances',
                    'gift_idea', 'relationship_milestone' => '/gifts-anniversaries',
                    'travel_inbox_item' => '/trips',
                    default => '/dashboard',
                };

                return [
                    'uuid' => $event->uuid,
                    'kind' => $event->kind,
                    'title' => $event->title,
                    'source' => $event->source,
                    'occurred_at' => $event->occurred_at,
                    'href' => $href,
                ];
            })->values()->all();
    }

    private function actionItems(int $spaceId, int $userId): array
    {
        $items = collect();

        if (Schema::hasTable('event_tasks')) {
            $items = $items->merge(DB::table('event_tasks as task')
                ->join('calendar_events as event', 'event.id', '=', 'task.event_id')
                ->where('event.gallery_space_id', $spaceId)
                ->whereNull('task.completed_at')
                ->whereNotIn('event.status', ['completed', 'cancelled'])
                ->orderByRaw('task.due_at is null, task.due_at')
                ->limit(8)
                ->get(['task.id', 'task.title', 'task.due_at', 'event.uuid as event_uuid'])
                ->map(fn ($task) => [
                    'key' => 'event-task-' . $task->id,
                    'type' => 'Úkol k události',
                    'title' => $task->title,
                    'due_at' => $task->due_at,
                    'href' => '/calendar/events/' . $task->event_uuid,
                    'tone' => 'violet',
                ]));
        }

        if (Schema::hasTable('travel_inbox_items')) {
            $today = now()->startOfDay();
            $items = $items->merge(DB::table('travel_inbox_items as item')
                ->leftJoin('trips as trip', 'trip.id', '=', 'item.trip_id')
                ->leftJoin('calendar_events as event', 'event.id', '=', 'item.event_id')
                ->where('item.gallery_space_id', $spaceId)
                ->whereIn('item.state', ['inbox', 'assigned'])
                ->where(function ($active) use ($today) {
                    $active->where(fn ($unlinked) => $unlinked->whereNull('item.trip_id')->whereNull('item.event_id'))
                        ->orWhere('trip.end_date', '>=', $today->toDateString())
                        ->orWhereRaw('COALESCE(event.ends_at, event.starts_at) >= ?', [$today]);
                })
                ->orderByDesc('item.updated_at')
                ->limit(8)
                ->get(['item.uuid', 'item.title', 'item.trip_id', 'item.event_id', 'item.updated_at'])
                ->map(fn ($item) => [
                    'key' => 'travel-' . $item->uuid,
                    'type' => 'Cestovní podklad',
                    'title' => $item->title,
                    'due_at' => $item->updated_at,
                    'href' => $item->trip_id ? '/trips/' . $item->trip_id . '/plan' : ($item->event_id ? '/calendar' : '/trips'),
                    'tone' => 'sky',
                ]));
        }

        if (Schema::hasTable('gift_ideas')) {
            $items = $items->merge(DB::table('gift_ideas')
                ->where('gallery_space_id', $spaceId)
                ->whereIn('status', ['idea', 'planned'])
                ->orderByRaw('due_date is null, due_date')
                ->limit(6)
                ->when(Schema::hasColumn('gift_ideas', 'visibility') && Schema::hasColumn('gift_ideas', 'private_to_user_id'), fn ($query) => $query->where(fn ($visible) => $visible->where('visibility', 'shared')->orWhere('private_to_user_id', $userId)))
                ->get(['uuid', 'title', 'due_date'])
                ->map(fn ($item) => [
                    'key' => 'gift-' . $item->uuid,
                    'type' => 'Dárek',
                    'title' => $item->title,
                    'due_at' => $item->due_date,
                    'href' => '/gifts-anniversaries',
                    'tone' => 'pink',
                ]));
        }

        if (Schema::hasTable('shared_todos')) {
            $items = $items->merge(DB::table('shared_todos')
                ->where('gallery_space_id', $spaceId)
                ->where('status', 'open')
                ->orderByRaw('due_at is null, due_at')
                ->limit(8)
                ->get(['uuid', 'title', 'due_at', 'trip_id'])
                ->map(fn ($item) => [
                    'key' => 'todo-' . $item->uuid,
                    'type' => 'Společný úkol',
                    'title' => $item->title,
                    'due_at' => $item->due_at,
                    'href' => $item->trip_id ? '/trips/' . $item->trip_id . '/plan' : '/planning',
                    'tone' => 'emerald',
                ]));
        }

        return $items->sortBy(fn (array $item) => $item['due_at'] ?: '9999-12-31')->take(20)->values()->all();
    }
}
