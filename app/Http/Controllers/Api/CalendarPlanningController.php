<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventAttachment;
use App\Models\EventReminder;
use App\Models\EventTask;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Notifications\GalleryNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Shared calendar, trip preparation and memories workflow. */
class CalendarPlanningController extends Controller
{
    private const TYPES = ['event', 'trip', 'outing', 'birthday', 'anniversary', 'reservation', 'custom'];
    private const CHANNELS = ['database', 'email', 'push'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = $request->user();

        $events = $this->visibleEvents($user)
            ->where('starts_at', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->where('starts_at', '>=', $from)->orWhere('ends_at', '>=', $from))
            ->withCount(['tasks as open_tasks_count' => fn (Builder $q) => $q->whereNull('completed_at')])
            ->orderBy('starts_at')->get();
        // The warning is advisory only; it never blocks a shared plan or changes dates.
        $events->each(function (CalendarEvent $event) use ($events) {
            $eventEnd = $event->ends_at ?? $event->starts_at;
            $event->setAttribute('has_conflict', $events->contains(fn (CalendarEvent $other) => $other->id !== $event->id
                && $other->gallery_space_id === $event->gallery_space_id
                && $other->starts_at->lte($eventEnd)
                && ($other->ends_at ?? $other->starts_at)->gte($event->starts_at)));
        });

        return response()->json([
            'events' => $events->flatMap(fn (CalendarEvent $event) => $this->occurrences($event, $from, $to)),
            'spaces' => $user->gallerySpaces()->select('gallery_spaces.id', 'name')->get(),
            'trips' => DB::table('trips')->whereIn('gallery_space_id', $this->spaceIds($user))
                ->orderBy('start_date')->get(['id', 'gallery_space_id', 'name', 'start_date', 'end_date', 'budget', 'currency']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatedEvent($request, false);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        $this->validateTripAndAlbum($data, $space->id);

        $event = CalendarEvent::create($data + ['created_by' => $user->id]);
        $event->participants()->syncWithoutDetaching([$user->id => ['role' => 'owner', 'response' => 'accepted']]);
        $this->syncParticipants($event, $data['participant_ids'] ?? [], $user);
        $this->syncReminders($event, $data['reminders'] ?? [], $user);
        $this->notifyParticipants($event, $user, 'calendar.created', "Nová akce: {$event->title}");

        return response()->json($this->eventPayload($event->fresh(), $user), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $event = $this->findVisibleEvent($user, $uuid);
        return response()->json($this->eventPayload($event, $user));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $event = $this->findVisibleEvent($user, $uuid);
        $this->ensureCanEdit($event, $user);
        $data = $this->validatedEvent($request, true);
        $spaceId = (int) ($data['gallery_space_id'] ?? $event->gallery_space_id);
        $this->ownedSpace($user, $spaceId);
        $data['gallery_space_id'] = $spaceId;
        $this->validateTripAndAlbum($data + ['trip_id' => $event->trip_id, 'album_id' => $event->album_id], $spaceId);
        $event->update(collect($data)->except(['participant_ids', 'reminders'])->all());
        if (array_key_exists('participant_ids', $data)) $this->syncParticipants($event, $data['participant_ids'], $user);
        if (array_key_exists('reminders', $data)) $this->syncReminders($event, $data['reminders'], $user);

        return response()->json($this->eventPayload($event->fresh(), $user));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid);
        $this->ensureCanEdit($event, $request->user());
        $event->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function respond(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $event = $this->findVisibleEvent($user, $uuid);
        abort_unless($event->participants()->whereKey($user->id)->exists(), 403, 'Odpovědět mohou pouze účastníci akce.');
        $data = $request->validate(['response' => 'required|in:accepted,tentative,declined']);
        $event->participants()->updateExistingPivot($user->id, ['response' => $data['response'], 'updated_at' => now()]);

        if ($event->created_by !== $user->id) {
            $labels = ['accepted' => 'potvrdil/a účast', 'tentative' => 'označil/a účast jako možná', 'declined' => 'odmítl/a účast'];
            $event->creator?->notify(new GalleryNotification('calendar.response', "{$user->name} {$labels[$data['response']]}: {$event->title}", '/calendar/events/' . $event->uuid, '📅'));
        }

        return response()->json($this->eventPayload($event->fresh(), $user));
    }

    public function storeTask(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $data = $request->validate(['title' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'due_at' => 'nullable|date', 'priority' => 'nullable|in:low,normal,high', 'assigned_to' => 'nullable|integer']);
        $this->ensureParticipant($event, $data['assigned_to'] ?? null);
        $data['sort_order'] = ((int) $event->tasks()->max('sort_order')) + 1;
        return response()->json($event->tasks()->create($data), 201);
    }

    public function updateTask(Request $request, string $uuid, int $taskId): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $task = $event->tasks()->findOrFail($taskId);
        $data = $request->validate(['title' => 'sometimes|string|max:255', 'notes' => 'nullable|string|max:5000', 'due_at' => 'nullable|date', 'priority' => 'nullable|in:low,normal,high', 'assigned_to' => 'nullable|integer', 'completed' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0']);
        $this->ensureParticipant($event, $data['assigned_to'] ?? null);
        if (array_key_exists('completed', $data)) { $data['completed_at'] = $data['completed'] ? now() : null; unset($data['completed']); }
        $task->update($data);
        return response()->json($task->fresh());
    }

    public function destroyTask(Request $request, string $uuid, int $taskId): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $event->tasks()->findOrFail($taskId)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function storeAttachment(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $data = $request->validate(['media_item_id' => 'nullable|integer', 'label' => 'nullable|string|max:255', 'external_url' => 'nullable|url|max:2048', 'reference_code' => 'nullable|string|max:255', 'kind' => 'nullable|in:attachment,reservation,ticket,document']);
        if (empty($data['media_item_id']) && empty($data['external_url']) && empty($data['reference_code'])) abort(422, 'Přidejte médium, odkaz nebo referenci.');
        if (!empty($data['external_url']) && !Str::startsWith($data['external_url'], 'https://')) abort(422, 'Odkazy musí používat HTTPS.');
        if (!empty($data['media_item_id'])) MediaItem::where('id', $data['media_item_id'])->where('gallery_space_id', $event->gallery_space_id)->whereNull('trashed_at')->firstOrFail();
        return response()->json($event->attachments()->create($data)->load('media:id,uuid,display_title,original_filename'), 201);
    }

    public function destroyAttachment(Request $request, string $uuid, int $attachmentId): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $event->attachments()->findOrFail($attachmentId)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function mediaSuggestions(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid);
        $from = $event->starts_at->copy()->subHours(6); $to = ($event->ends_at ?? $event->starts_at)->copy()->addHours(12);
        $query = MediaItem::where('gallery_space_id', $event->gallery_space_id)->whereNull('trashed_at')->whereBetween('taken_at', [$from, $to]);
        if ($event->latitude !== null && $event->longitude !== null) $query->whereRaw('ABS(latitude - ?) < 0.15 AND ABS(longitude - ?) < 0.15', [$event->latitude, $event->longitude]);
        return response()->json(['candidates' => $query->latest('taken_at')->limit(48)->get(['id', 'uuid', 'display_title', 'original_filename', 'taken_at'])]);
    }

    public function applyMediaSuggestions(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $data = $request->validate(['media_ids' => 'required|array|min:1|max:48', 'media_ids.*' => 'integer']);
        $media = MediaItem::where('gallery_space_id', $event->gallery_space_id)->whereNull('trashed_at')->whereIn('id', $data['media_ids'])->get();
        if ($media->count() !== count(array_unique($data['media_ids']))) abort(422, 'Některá vybraná média nejsou dostupná.');
        foreach ($media as $item) $event->attachments()->firstOrCreate(['media_item_id' => $item->id], ['kind' => 'memory']);
        return response()->json($event->attachments()->with('media:id,uuid,display_title,original_filename')->get());
    }

    public function story(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid);
        $mediaIds = $event->attachments()->whereNotNull('media_item_id')->pluck('media_item_id')->all();
        $summary = $event->starts_at->locale('cs')->translatedFormat('j. F Y') . ' · ' . ($event->place_name ?: 'společný zážitek') . ' · ' . count($mediaIds) . ' vzpomínek';
        DB::table('event_stories')->updateOrInsert(['event_id' => $event->id], ['summary' => $summary, 'media_ids' => json_encode($mediaIds), 'generated_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        return response()->json(DB::table('event_stories')->where('event_id', $event->id)->first());
    }

    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json(DB::table('travel_inbox_items')->whereIn('gallery_space_id', $this->spaceIds($user))->when($request->query('state'), fn ($q, $state) => $q->where('state', $state))->latest()->get());
    }

    public function storeInbox(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'source_url' => 'nullable|url|max:2048', 'kind' => 'nullable|in:link,note,reservation,idea,file', 'trip_id' => 'nullable|integer', 'event_id' => 'nullable|integer']);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        if (!empty($data['source_url']) && !Str::startsWith($data['source_url'], 'https://')) abort(422, 'Odkazy musí používat HTTPS.');
        $this->validateInboxLinks($data, $space->id);
        $id = DB::table('travel_inbox_items')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'added_by' => $user->id, 'state' => 'inbox', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('travel_inbox_items')->find($id), 201);
    }

    public function updateInbox(Request $request, string $uuid): JsonResponse
    {
        $item = DB::table('travel_inbox_items')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $data = $request->validate(['title' => 'sometimes|string|max:255', 'notes' => 'nullable|string|max:5000', 'source_url' => 'nullable|url|max:2048', 'kind' => 'nullable|in:link,note,reservation,idea,file', 'state' => 'nullable|in:inbox,assigned,archived', 'trip_id' => 'nullable|integer', 'event_id' => 'nullable|integer']);
        if (!empty($data['source_url']) && !Str::startsWith($data['source_url'], 'https://')) abort(422, 'Odkazy musí používat HTTPS.');
        $this->validateInboxLinks($data, $item->gallery_space_id);
        DB::table('travel_inbox_items')->where('id', $item->id)->update($data + ['updated_at' => now()]);
        return response()->json(DB::table('travel_inbox_items')->find($item->id));
    }

    public function destroyInbox(Request $request, string $uuid): JsonResponse
    {
        DB::table('travel_inbox_items')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function storeExpense(Request $request, int $tripId): JsonResponse
    {
        $user = $request->user(); $trip = $this->findTrip($user, $tripId);
        $data = $request->validate(['title' => 'required|string|max:255', 'category' => 'nullable|in:transport,accommodation,food,activities,insurance,other', 'amount' => 'required|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'paid_by' => 'nullable|string|max:120', 'state' => 'nullable|in:planned,actual', 'occurred_at' => 'nullable|date', 'split' => 'nullable|array', 'event_id' => 'nullable|integer']);
        if (!empty($data['event_id'])) CalendarEvent::where('id', $data['event_id'])->where('gallery_space_id', $trip->gallery_space_id)->firstOrFail();
        $id = DB::table('trip_expenses')->insertGetId($data + ['trip_id' => $tripId, 'created_by' => $user->id, 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'state' => $data['state'] ?? 'actual', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('trip_expenses')->find($id), 201);
    }

    public function tripPlanning(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->findTrip($request->user(), $tripId);
        $expenses = DB::table('trip_expenses')->where('trip_id', $tripId)->latest('occurred_at')->latest('id')->get();
        $totals = $expenses->groupBy('state')->map(fn ($rows) => $rows->sum('amount'));
        return response()->json([
            'expenses' => $expenses,
            'totals' => ['planned' => (float) ($totals['planned'] ?? 0), 'actual' => (float) ($totals['actual'] ?? 0), 'budget' => (float) ($trip->budget ?? 0), 'currency' => $trip->currency ?? 'CZK'],
            'route_variants' => DB::table('trip_route_variants')->where('trip_id', $tripId)->latest()->get(),
        ]);
    }

    public function destroyExpense(Request $request, int $tripId, int $expenseId): JsonResponse
    {
        $this->findTrip($request->user(), $tripId);
        DB::table('trip_expenses')->where('trip_id', $tripId)->where('id', $expenseId)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function storeRouteVariant(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->findTrip($request->user(), $tripId);
        $data = $request->validate(['title' => 'required|string|max:255', 'strategy' => 'nullable|in:fastest,cheapest,scenic,low-carbon,custom', 'transport_modes' => 'nullable|array|max:10', 'estimated_minutes' => 'nullable|integer|min:0|max:10080', 'estimated_cost' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'data' => 'nullable|array']);
        $id = DB::table('trip_route_variants')->insertGetId($data + ['trip_id' => $tripId, 'created_by' => $request->user()->id, 'strategy' => $data['strategy'] ?? 'custom', 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('trip_route_variants')->find($id), 201);
    }

    public function selectRouteVariant(Request $request, int $tripId, int $variantId): JsonResponse
    {
        $this->findTrip($request->user(), $tripId);
        DB::transaction(function () use ($tripId, $variantId) {
            DB::table('trip_route_variants')->where('trip_id', $tripId)->update(['is_selected' => false, 'updated_at' => now()]);
            DB::table('trip_route_variants')->where('trip_id', $tripId)->where('id', $variantId)->update(['is_selected' => true, 'updated_at' => now()]);
        });
        return response()->json(DB::table('trip_route_variants')->where('trip_id', $tripId)->where('id', $variantId)->firstOrFail());
    }

    public function timeCapsules(Request $request): JsonResponse
    {
        return response()->json(DB::table('time_capsules')->whereIn('gallery_space_id', $this->spaceIds($request->user()))->where('created_by', $request->user()->id)->orderBy('deliver_at')->get());
    }

    public function storeTimeCapsule(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'recipient_user_id' => 'nullable|integer', 'event_id' => 'nullable|integer', 'media_item_id' => 'nullable|integer', 'title' => 'required|string|max:255', 'message' => 'nullable|string|max:10000', 'deliver_at' => 'required|date|after:now']);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        if (!empty($data['recipient_user_id']) && !$space->members()->whereKey($data['recipient_user_id'])->exists() && $space->owner_id !== (int) $data['recipient_user_id']) abort(422, 'Příjemce není členem prostoru.');
        if (!empty($data['event_id'])) CalendarEvent::where('id', $data['event_id'])->where('gallery_space_id', $space->id)->firstOrFail();
        if (!empty($data['media_item_id'])) MediaItem::where('id', $data['media_item_id'])->where('gallery_space_id', $space->id)->firstOrFail();
        $id = DB::table('time_capsules')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'created_by' => $user->id, 'status' => 'sealed', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('time_capsules')->find($id), 201);
    }

    public function weeklyOverview(Request $request): JsonResponse
    {
        $user = $request->user(); $from = now()->startOfWeek(); $to = now()->endOfWeek();
        $events = $this->visibleEvents($user)->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get();
        $eventIds = $events->pluck('id');
        $unseen = MediaItem::whereIn('gallery_space_id', $this->spaceIds($user))->whereNull('trashed_at')->where('taken_at', '<', now()->subYear())->where('is_favorite', false)->latest('taken_at')->limit(6)->get(['uuid', 'display_title', 'taken_at']);
        return response()->json(['period' => [$from->toDateString(), $to->toDateString()], 'events' => $events, 'open_tasks' => EventTask::whereIn('event_id', $eventIds)->whereNull('completed_at')->orderBy('due_at')->get(), 'travel_inbox' => DB::table('travel_inbox_items')->whereIn('gallery_space_id', $this->spaceIds($user))->where('state', 'inbox')->latest()->limit(8)->get(), 'rediscover' => $unseen]);
    }

    public function storePushSubscription(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|url|max:2048', 'keys' => 'required|array', 'keys.p256dh' => 'required|string|max:512', 'keys.auth' => 'required|string|max:512']);
        if (!Str::startsWith($data['endpoint'], 'https://')) abort(422, 'Push endpoint musí používat HTTPS.');
        DB::table('push_subscriptions')->updateOrInsert(['endpoint' => $data['endpoint']], ['user_id' => $request->user()->id, 'keys' => json_encode($data['keys']), 'user_agent' => Str::limit((string) $request->userAgent(), 1024), 'last_seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        return response()->json(['status' => 'saved'], 201);
    }

    public function destroyPushSubscription(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => 'required|url|max:2048']);
        DB::table('push_subscriptions')->where('user_id', $request->user()->id)->where('endpoint', $request->input('endpoint'))->delete();
        return response()->json(['status' => 'deleted']);
    }

    private function validatedEvent(Request $request, bool $partial): array
    {
        $rule = fn (string $required) => $partial ? 'sometimes' : $required;
        $data = $request->validate([
            'gallery_space_id' => $rule('required') . '|integer', 'trip_id' => 'nullable|integer', 'album_id' => 'nullable|integer',
            'title' => $rule('required') . '|string|max:160', 'description' => 'nullable|string|max:10000', 'type' => 'nullable|' . Rule::in(self::TYPES), 'status' => 'nullable|in:planned,confirmed,completed,cancelled',
            'starts_at' => $rule('required') . '|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at', 'all_day' => 'nullable|boolean', 'timezone' => 'nullable|timezone',
            'place_name' => 'nullable|string|max:255', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'departure_buffer_minutes' => 'nullable|integer|min:0|max:1440',
            'recurrence_rule' => 'nullable|array', 'recurrence_rule.frequency' => 'nullable|in:daily,weekly,monthly,yearly', 'recurrence_rule.interval' => 'nullable|integer|min:1|max:52', 'recurrence_rule.until' => 'nullable|date',
            'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/', 'is_private' => 'nullable|boolean', 'metadata' => 'nullable|array',
            'participant_ids' => 'nullable|array|max:30', 'participant_ids.*' => 'integer', 'reminders' => 'nullable|array|max:12', 'reminders.*.minutes_before' => 'required_with:reminders|integer|min:0|max:525600', 'reminders.*.channel' => 'required_with:reminders|' . Rule::in(self::CHANNELS), 'reminders.*.user_id' => 'nullable|integer',
        ]);
        if (isset($data['starts_at']) && isset($data['recurrence_rule']['until']) && Carbon::parse($data['recurrence_rule']['until'])->lt(Carbon::parse($data['starts_at'])->startOfDay())) abort(422, 'Konec opakování nesmí být před začátkem akce.');
        return $data;
    }

    private function eventPayload(CalendarEvent $event, ?User $viewer = null): array
    {
        $event->load(['participants:id,name,email', 'tasks.assignee:id,name', 'attachments.media:id,uuid,display_title,original_filename', 'reminders']);
        $payload = $event->toArray();
        $payload['budget'] = $event->trip_id ? DB::table('trip_expenses')->where('trip_id', $event->trip_id)->selectRaw("state, SUM(amount) as total")->groupBy('state')->pluck('total', 'state') : [];
        $payload['route_variants'] = $event->trip_id ? DB::table('trip_route_variants')->where('trip_id', $event->trip_id)->get() : [];
        $payload['departure_at'] = $event->departure_buffer_minutes ? $event->starts_at->copy()->subMinutes($event->departure_buffer_minutes)->toIso8601String() : null;
        $payload['my_response'] = $viewer ? $event->participants->firstWhere('id', $viewer->id)?->pivot?->response : null;
        return $payload;
    }

    private function occurrences(CalendarEvent $event, Carbon $from, Carbon $to): array
    {
        $rule = $event->recurrence_rule; if (empty($rule['frequency'])) return [$event->toArray()];
        $cursor = $event->starts_at->copy(); $until = !empty($rule['until']) ? Carbon::parse($rule['until'])->endOfDay() : $to;
        $interval = max(1, (int) ($rule['interval'] ?? 1)); $duration = $event->ends_at ? $event->ends_at->diffInSeconds($event->starts_at) : null; $items = [];
        $exceptions = DB::table('calendar_event_exceptions')->where('event_id', $event->id)->get()->keyBy(fn ($exception) => Carbon::parse($exception->occurs_at)->format('Y-m-d H:i:s'));
        for ($count = 0; $count < 365 && $cursor->lte($to) && $cursor->lte($until); $count++) {
            $exception = $exceptions->get($cursor->format('Y-m-d H:i:s'));
            if ($cursor->gte($from) && (!$exception || $exception->action !== 'skip')) {
                $startsAt = $exception?->replacement_starts_at ? Carbon::parse($exception->replacement_starts_at) : $cursor;
                $endsAt = $exception?->replacement_ends_at ? Carbon::parse($exception->replacement_ends_at) : ($duration ? $startsAt->copy()->addSeconds($duration) : null);
                $row = $event->toArray(); $row['title'] = $exception?->replacement_title ?: $event->title; $row['occurrence_start'] = $startsAt->toIso8601String(); $row['occurrence_end'] = $endsAt?->toIso8601String(); $row['is_exception'] = (bool) $exception; $items[] = $row;
            }
            $cursor = match ($rule['frequency']) { 'daily' => $cursor->addDays($interval), 'weekly' => $cursor->addWeeks($interval), 'monthly' => $cursor->addMonthsNoOverflow($interval), default => $cursor->addYearsNoOverflow($interval) };
        }
        return $items;
    }

    private function spaceIds(User $user): array { return $user->gallerySpaces()->pluck('gallery_spaces.id')->all(); }
    private function ownedSpace(User $user, int $id): GallerySpace { return $user->gallerySpaces()->whereKey($id)->firstOrFail(); }
    private function visibleEvents(User $user): Builder { return CalendarEvent::whereIn('gallery_space_id', $this->spaceIds($user))->where(fn (Builder $q) => $q->where('is_private', false)->orWhere('created_by', $user->id)->orWhereHas('participants', fn (Builder $p) => $p->whereKey($user->id))); }
    private function findVisibleEvent(User $user, string $uuid): CalendarEvent { return $this->visibleEvents($user)->where('uuid', $uuid)->firstOrFail(); }
    private function ensureCanEdit(CalendarEvent $event, User $user): void { if ($event->created_by !== $user->id && !$event->participants()->whereKey($user->id)->wherePivot('role', 'editor')->exists()) abort(403, 'Akci může upravovat jen autor nebo editor.'); }
    private function ensureParticipant(CalendarEvent $event, ?int $userId): void { if ($userId && !$event->participants()->whereKey($userId)->exists()) abort(422, 'Úkol lze přiřadit pouze účastníkovi akce.'); }
    private function findTrip(User $user, int $id): object { return DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $this->spaceIds($user))->firstOrFail(); }
    private function validateTripAndAlbum(array $data, int $spaceId): void { if (!empty($data['trip_id'])) DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); if (!empty($data['album_id'])) DB::table('albums')->where('id', $data['album_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); }
    private function validateInboxLinks(array $data, int $spaceId): void { if (!empty($data['trip_id'])) DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); if (!empty($data['event_id'])) CalendarEvent::where('id', $data['event_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); }
    private function syncParticipants(CalendarEvent $event, array $ids, User $actor): void { $valid = User::whereIn('id', $ids)->whereHas('gallerySpaces', fn (Builder $q) => $q->where('gallery_spaces.id', $event->gallery_space_id))->pluck('id')->all(); if (count(array_unique($ids)) !== count($valid)) abort(422, 'Všichni účastníci musí být členy společného prostoru.'); $event->participants()->syncWithoutDetaching(collect($valid)->reject(fn ($id) => $id === $actor->id)->mapWithKeys(fn ($id) => [$id => ['role' => 'guest', 'response' => 'pending']])->all()); }
    private function syncReminders(CalendarEvent $event, array $reminders, User $actor): void { if (!$reminders) { $event->reminders()->delete(); return; } $event->reminders()->delete(); foreach ($reminders as $reminder) { $recipient = $reminder['user_id'] ?? $actor->id; $this->ensureParticipant($event, $recipient); $event->reminders()->create(['user_id' => $recipient, 'channel' => $reminder['channel'], 'remind_at' => $event->starts_at->copy()->subMinutes((int) $reminder['minutes_before']), 'status' => 'pending']); } }
    private function notifyParticipants(CalendarEvent $event, User $actor, string $type, string $message): void { foreach ($event->participants()->where('users.id', '!=', $actor->id)->get() as $user) $user->notify(new GalleryNotification($type, $message, '/calendar/events/' . $event->uuid, '📅')); }
}
