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
use Illuminate\Support\Facades\Schema;
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
        $milestones = DB::table('relationship_milestones')
            ->whereIn('gallery_space_id', $this->spaceIds($user))
            ->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('created_by', $user->id))
            ->get(['uuid', 'title', 'icon', 'occurred_on', 'gallery_space_id'])
            ->flatMap(function ($milestone) use ($from, $to) {
                $occurrences = [];
                for ($year = $from->year; $year <= $to->year; $year++) {
                    $original = Carbon::parse($milestone->occurred_on);
                    $date = Carbon::create($year, $original->month, min($original->day, Carbon::create($year, $original->month, 1)->daysInMonth))->toDateString();
                    if ($date >= $from->toDateString() && $date <= $to->toDateString()) $occurrences[] = ['uuid' => $milestone->uuid, 'title' => $milestone->title, 'icon' => $milestone->icon, 'occurrence_date' => $date];
                }
                return $occurrences;
            })->values();

        return response()->json([
            'events' => $events->flatMap(fn (CalendarEvent $event) => $this->occurrences($event, $from, $to)),
            'milestones' => $milestones,
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
        $this->syncTripSchedule($event);
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
        $this->syncTripSchedule($event);
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
        $attachment = $event->attachments()->create($data);
        if ($event->trip_id && in_array($attachment->kind, ['reservation', 'ticket', 'document'], true)) {
            $type = ['reservation' => 'booking', 'ticket' => 'ticket', 'document' => 'other'][$attachment->kind];
            $title = $attachment->label ?: ($attachment->reference_code ?: 'Příloha z kalendáře');
            DB::table('trip_document_checks')->updateOrInsert(['trip_id' => $event->trip_id, 'title' => $title, 'reference' => $attachment->reference_code], ['type' => $type, 'created_by' => $request->user()->id, 'status' => 'ready', 'updated_at' => now(), 'created_at' => now()]);
        }
        return response()->json($attachment->load('media:id,uuid,display_title,original_filename'), 201);
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

    /** Close the loop: an attended event becomes a shared gallery memory. */
    public function createSharedMemory(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        abort_if($event->starts_at->isFuture(), 422, 'Společnou vzpomínku lze vytvořit až po začátku akce.');
        $data = $request->validate(['title' => 'nullable|string|max:160', 'note' => 'nullable|string|max:5000', 'media_ids' => 'nullable|array|max:48', 'media_ids.*' => 'integer|distinct']);
        $mediaIds = $data['media_ids'] ?? $event->attachments()->whereNotNull('media_item_id')->pluck('media_item_id')->all();
        $media = MediaItem::where('gallery_space_id', $event->gallery_space_id)->whereNull('trashed_at')->whereIn('id', $mediaIds)->get();
        if ($media->count() !== count($mediaIds)) abort(422, 'Některá vybraná média nejsou dostupná.');
        foreach ($media as $item) $event->attachments()->firstOrCreate(['media_item_id' => $item->id], ['kind' => 'memory']);
        $row = ['gallery_space_id' => $event->gallery_space_id, 'created_by' => $request->user()->id, 'calendar_event_id' => $event->id, 'trip_id' => $event->trip_id, 'title' => $data['title'] ?? $event->title, 'note' => $data['note'] ?? $event->description, 'happened_on' => $event->starts_at->toDateString(), 'media_item_ids' => json_encode($media->pluck('id')->all()), 'is_favorite' => true, 'updated_at' => now()];
        $existing = DB::table('shared_memory_moments')->where('calendar_event_id', $event->id)->first();
        if (! $existing && $event->trip_id) $existing = DB::table('shared_memory_moments')->where('trip_id', $event->trip_id)->first();
        if ($existing) { DB::table('shared_memory_moments')->where('id', $existing->id)->update($row); $memoryId = $existing->id; }
        else $memoryId = DB::table('shared_memory_moments')->insertGetId($row + ['uuid' => (string) Str::uuid(), 'created_at' => now()]);
        $event->update(['status' => 'completed']);
        return response()->json(DB::table('shared_memory_moments')->find($memoryId), 201);
    }

    /** Promote an ordinary shared calendar event into a trip workspace exactly once. */
    public function createTrip(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        if ($event->trip_id) return response()->json(DB::table('trips')->find($event->trip_id));
        $id = DB::table('trips')->insertGetId(['gallery_space_id' => $event->gallery_space_id, 'created_by' => $request->user()->id, 'name' => $event->title, 'description' => $event->description, 'start_date' => $event->starts_at->toDateString(), 'end_date' => ($event->ends_at ?? $event->starts_at)->toDateString(), 'notes' => $event->place_name ? "Vzniklo z kalendářové akce · {$event->place_name}" : 'Vzniklo z kalendářové akce', 'status' => 'planned', 'timezone' => $event->timezone ?? 'Europe/Prague', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $event->update(['trip_id' => $id]);
        if ($event->place_name) DB::table('trip_waypoints')->insert(['trip_id' => $id, 'place_name' => $event->place_name, 'latitude' => $event->latitude, 'longitude' => $event->longitude, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $now = now();
        foreach ([['Domluvit rozpočet a kdo co zaplatí', 14, 'high'], ['Zkontrolovat doklady a rezervace', 7, 'high'], ['Dokončit balení na cestu', 2, 'normal']] as $order => [$title, $daysBefore, $priority]) {
            DB::table('event_tasks')->insert(['event_id' => $event->id, 'title' => $title, 'due_at' => $event->starts_at->copy()->subDays($daysBefore), 'priority' => $priority, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
        }
        return response()->json(DB::table('trips')->find($id), 201);
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
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'source_url' => 'nullable|url|max:2048', 'kind' => 'nullable|in:link,note,reservation,idea,file', 'trip_id' => 'nullable|integer', 'trip_day_id' => 'nullable|integer', 'trip_activity_id' => 'nullable|integer', 'event_id' => 'nullable|integer']);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        if (!empty($data['source_url']) && !Str::startsWith($data['source_url'], 'https://')) abort(422, 'Odkazy musí používat HTTPS.');
        $data = $this->validateInboxLinks($data, $space->id);
        $id = DB::table('travel_inbox_items')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'added_by' => $user->id, 'state' => 'inbox', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('travel_inbox_items')->find($id), 201);
    }

    public function updateInbox(Request $request, string $uuid): JsonResponse
    {
        $item = DB::table('travel_inbox_items')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $data = $request->validate(['title' => 'sometimes|string|max:255', 'notes' => 'nullable|string|max:5000', 'source_url' => 'nullable|url|max:2048', 'kind' => 'nullable|in:link,note,reservation,idea,file', 'state' => 'nullable|in:inbox,assigned,archived', 'trip_id' => 'nullable|integer', 'trip_day_id' => 'nullable|integer', 'trip_activity_id' => 'nullable|integer', 'event_id' => 'nullable|integer']);
        if (!empty($data['source_url']) && !Str::startsWith($data['source_url'], 'https://')) abort(422, 'Odkazy musí používat HTTPS.');
        $data = $this->validateInboxLinks($data, $item->gallery_space_id);
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
        $data = $request->validate(['title' => 'required|string|max:255', 'category' => 'nullable|in:transport,accommodation,food,activities,insurance,other', 'amount' => 'required|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'paid_by' => 'nullable|string|max:120', 'paid_by_user_id' => 'nullable|integer', 'state' => 'nullable|in:planned,actual', 'occurred_at' => 'nullable|date', 'split' => 'nullable|array|min:1|max:20', 'split.*.user_id' => 'required_with:split|integer', 'split.*.amount' => 'required_with:split|numeric|min:0', 'event_id' => 'nullable|integer']);
        if (!empty($data['event_id'])) CalendarEvent::where('id', $data['event_id'])->where('gallery_space_id', $trip->gallery_space_id)->firstOrFail();
        if (!empty($data['paid_by_user_id'])) abort_unless(DB::table('gallery_space_user')->where('gallery_space_id', $trip->gallery_space_id)->where('user_id', $data['paid_by_user_id'])->exists(), 422, 'Plátce musí být členem společného prostoru.');
        if (!empty($data['split'])) { foreach ($data['split'] as $share) abort_unless(DB::table('gallery_space_user')->where('gallery_space_id', $trip->gallery_space_id)->where('user_id', $share['user_id'])->exists(), 422, 'Podíl patří neznámému uživateli.'); if (round(array_sum(array_column($data['split'], 'amount')), 2) !== round((float) $data['amount'], 2)) abort(422, 'Součet podílů musí odpovídat výdaji.'); $data['split'] = json_encode($data['split']); }
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
            'members' => DB::table('gallery_space_user as membership')->join('users', 'users.id', '=', 'membership.user_id')->where('membership.gallery_space_id', $trip->gallery_space_id)->orderBy('users.name')->get(['users.id', 'users.name']),
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

    /** Schedule a shared memory evening from moments already saved by the couple. */
    public function scheduleMemoryEvening(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'scheduled_at' => 'required|date|after:now',
            'moment_uuids' => 'required|array|min:1|max:6',
            'moment_uuids.*' => 'uuid|distinct',
            'title' => 'nullable|string|max:160',
        ]);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        $moments = DB::table('shared_memory_moments')->where('gallery_space_id', $space->id)->whereIn('uuid', $data['moment_uuids'])->get();
        abort_unless($moments->count() === count($data['moment_uuids']), 422, 'Některé společné vzpomínky nejsou dostupné.');

        $startsAt = Carbon::parse($data['scheduled_at']);
        $event = CalendarEvent::create([
            'gallery_space_id' => $space->id,
            'created_by' => $user->id,
            'title' => $data['title'] ?? 'Večer se vzpomínkami',
            'description' => 'Společný návrat k zážitkům: ' . $moments->pluck('title')->implode(' · '),
            'type' => 'event',
            'status' => 'planned',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'timezone' => 'Europe/Prague',
            'color' => '#ec4899',
            'metadata' => ['memory_moment_uuids' => $moments->pluck('uuid')->values()->all(), 'memory_evening' => true],
        ]);

        $memberIds = $space->members()->pluck('users.id')->push($user->id)->unique()->values();
        $event->participants()->sync($memberIds->mapWithKeys(fn (int $id) => [$id => ['role' => $id === $user->id ? 'owner' : 'guest', 'response' => $id === $user->id ? 'accepted' : 'pending']])->all());
        $mediaIds = $moments->flatMap(fn ($moment) => json_decode($moment->media_item_ids ?: '[]', true) ?: [])->filter('is_numeric')->unique()->values();
        $allowedMedia = MediaItem::where('gallery_space_id', $space->id)->whereNull('trashed_at')->whereIn('id', $mediaIds)->pluck('id');
        foreach ($allowedMedia as $mediaId) $event->attachments()->firstOrCreate(['media_item_id' => $mediaId], ['kind' => 'memory']);
        foreach ($memberIds as $memberId) $event->reminders()->create(['user_id' => $memberId, 'channel' => 'database', 'remind_at' => $startsAt->copy()->subDay(), 'status' => 'pending']);

        return response()->json($this->eventPayload($event->fresh(), $user), 201);
    }

    public function weeklyOverview(Request $request): JsonResponse
    {
        $user = $request->user(); $from = now()->startOfWeek(); $to = now()->endOfWeek();
        $events = $this->visibleEvents($user)->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get();
        $eventIds = $events->pluck('id');
        $unseen = MediaItem::whereIn('gallery_space_id', $this->spaceIds($user))->whereNull('trashed_at')->where('taken_at', '<', now()->subYear())->where('is_favorite', false)->latest('taken_at')->limit(6)->get(['uuid', 'display_title', 'taken_at']);
        $onThisDay = MediaItem::whereIn('gallery_space_id', $this->spaceIds($user))->whereNull('trashed_at')->whereNotNull('taken_at')->whereYear('taken_at', '<', now()->year)->whereMonth('taken_at', now()->month)->whereDay('taken_at', now()->day)->orderByDesc('taken_at')->limit(12)->get(['id', 'uuid', 'display_title', 'original_filename', 'taken_at']);
        return response()->json(['period' => [$from->toDateString(), $to->toDateString()], 'events' => $events, 'open_tasks' => EventTask::whereIn('event_id', $eventIds)->whereNull('completed_at')->orderBy('due_at')->get(), 'travel_inbox' => DB::table('travel_inbox_items')->whereIn('gallery_space_id', $this->spaceIds($user))->where('state', 'inbox')->latest()->limit(8)->get(), 'rediscover' => $unseen, 'on_this_day' => $onThisDay]);
    }

    /** Suggest a shared outing from the places you have already curated together. */
    public function dateIdeas(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'theme' => 'nullable|in:any,rain,photo,budget,early', 'date' => 'nullable|date']);
        $space = $this->ownedSpace($request->user(), (int) $data['gallery_space_id']);
        $theme = $data['theme'] ?? 'any';
        $places = DB::table('places')->where('gallery_space_id', $space->id);
        if ($theme === 'rain') $places->where('is_rain_friendly', true);
        if ($theme === 'photo') $places->where('is_photogenic', true);
        if ($theme === 'early') $places->where('opens_early', true);
        if ($theme === 'budget') $places->whereNotNull('price_level')->where('price_level', '<=', 2);
        $ideas = $places->orderByDesc('personal_rating')->orderByDesc('is_photogenic')->orderByDesc('is_rain_friendly')->limit(20)->get()->map(function ($place) use ($theme) {
            $reasons = [];
            if ($place->personal_rating) $reasons[] = "hodnocení {$place->personal_rating}/5";
            if ($place->is_rain_friendly) $reasons[] = 'vhodné na déšť';
            if ($place->is_photogenic) $reasons[] = 'fotogenické';
            if ($place->opens_early) $reasons[] = 'otevírá brzy';
            if ($place->price_level && $place->price_level <= 2) $reasons[] = 'příznivá cena';
            return ['id' => $place->id, 'title' => $place->name, 'place_name' => collect([$place->city, $place->country])->filter()->join(', '), 'type' => $place->type, 'estimated_visit_minutes' => $place->estimated_visit_minutes, 'reason' => $reasons ? implode(' · ', $reasons) : ($theme === 'any' ? 'váš uložený tip' : 'odpovídá zvolenému filtru')];
        });
        return response()->json(['date' => $data['date'] ?? null, 'theme' => $theme, 'ideas' => $ideas]);
    }

    /** Return only shared free windows; private event details never leave the server. */
    public function sharedSlots(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'from' => 'nullable|date', 'days' => 'nullable|integer|between:1,28', 'duration_minutes' => 'nullable|integer|between:30,360']);
        $space = $this->ownedSpace($request->user(), (int) $data['gallery_space_id']);
        $from = Carbon::parse($data['from'] ?? now())->startOfDay(); $until = $from->copy()->addDays((int) ($data['days'] ?? 14)); $duration = (int) ($data['duration_minutes'] ?? 120);
        $members = $space->members()->select('users.id', 'users.preferences')->get();
        $events = CalendarEvent::where('gallery_space_id', $space->id)->where('starts_at', '<', $until)->where(fn (Builder $q) => $q->whereNull('ends_at')->where('starts_at', '>=', $from)->orWhere('ends_at', '>=', $from))->get(['starts_at', 'ends_at']);
        $slots = [];
        for ($day = $from->copy(); $day->lt($until) && count($slots) < 8; $day->addDay()) {
            $ranges = null;
            foreach ($members as $member) {
                $rules = collect($member->preferences['planning_availability'] ?? [])->filter(fn ($rule) => (int) ($rule['weekday'] ?? -1) === $day->dayOfWeek && !empty($rule['from']) && !empty($rule['to']));
                $memberRanges = $rules->map(fn ($rule) => ['start' => $day->copy()->setTimeFromTimeString($rule['from']), 'end' => $day->copy()->setTimeFromTimeString($rule['to'])])->values()->all();
                // An unset preference is deliberately treated as a conservative evening suggestion, not all-day availability.
                if (!$memberRanges) $memberRanges = [['start' => $day->copy()->setTime(18, 0), 'end' => $day->copy()->setTime(21, 0)]];
                $ranges = $ranges === null ? $memberRanges : $this->intersectTimeRanges($ranges, $memberRanges);
            }
            foreach ($ranges ?? [] as $range) for ($start = $range['start']->copy(); $start->copy()->addMinutes($duration)->lte($range['end']) && count($slots) < 8; $start->addHour()) {
                $end = $start->copy()->addMinutes($duration);
                $busy = $events->contains(fn (CalendarEvent $event) => $event->starts_at->lt($end) && ($event->ends_at ?? $event->starts_at)->gt($start));
                if (!$busy) $slots[] = ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'duration_minutes' => $duration];
            }
        }
        return response()->json(['slots' => $slots, 'member_ids' => $members->pluck('id')->values(), 'member_count' => $members->count(), 'notice' => 'Návrhy ukazují jen společný volný čas, nikoli obsah soukromých akcí.']);
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
        $exceptions = Schema::hasTable('calendar_event_exceptions') ? DB::table('calendar_event_exceptions')->where('event_id', $event->id)->get()->keyBy(fn ($exception) => Carbon::parse($exception->occurs_at)->format('Y-m-d H:i:s')) : collect();
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
    private function syncTripSchedule(CalendarEvent $event): void { if (!$event->trip_id) return; DB::table('trips')->where('id', $event->trip_id)->where('gallery_space_id', $event->gallery_space_id)->update(['start_date' => $event->starts_at->toDateString(), 'end_date' => ($event->ends_at ?? $event->starts_at)->toDateString(), 'updated_at' => now()]); }
    private function validateInboxLinks(array $data, int $spaceId): array { if (!empty($data['trip_id'])) DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); if (!empty($data['event_id'])) CalendarEvent::where('id', $data['event_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); $day = !empty($data['trip_day_id']) ? DB::table('trip_days as d')->join('trips as t', 't.id', '=', 'd.trip_id')->where('d.id', $data['trip_day_id'])->where('t.gallery_space_id', $spaceId)->select('d.trip_id')->firstOrFail() : null; if ($day && !empty($data['trip_id']) && (int) $data['trip_id'] !== (int) $day->trip_id) abort(422, 'Den itineráře musí patřit ke zvolené cestě.'); if ($day && empty($data['trip_id'])) $data['trip_id'] = $day->trip_id; if (!empty($data['trip_activity_id'])) { $activity = DB::table('trip_activities as a')->join('trip_days as d', 'd.id', '=', 'a.trip_day_id')->join('trips as t', 't.id', '=', 'd.trip_id')->where('a.id', $data['trip_activity_id'])->where('t.gallery_space_id', $spaceId)->select('a.trip_day_id', 'd.trip_id')->firstOrFail(); if ($day && (int) $activity->trip_day_id !== (int) $data['trip_day_id']) abort(422, 'Aktivita nepatří do vybraného dne.'); if (!empty($data['trip_id']) && (int) $activity->trip_id !== (int) $data['trip_id']) abort(422, 'Aktivita nepatří ke zvolené cestě.'); if (empty($data['trip_id'])) $data['trip_id'] = $activity->trip_id; } return $data; }
    private function intersectTimeRanges(array $left, array $right): array { $result = []; foreach ($left as $a) foreach ($right as $b) { $start = $a['start']->greaterThan($b['start']) ? $a['start']->copy() : $b['start']->copy(); $end = $a['end']->lessThan($b['end']) ? $a['end']->copy() : $b['end']->copy(); if ($start->lt($end)) $result[] = ['start' => $start, 'end' => $end]; } return $result; }
    private function syncParticipants(CalendarEvent $event, array $ids, User $actor): void { $valid = User::whereIn('id', $ids)->whereHas('gallerySpaces', fn (Builder $q) => $q->where('gallery_spaces.id', $event->gallery_space_id))->pluck('id')->all(); if (count(array_unique($ids)) !== count($valid)) abort(422, 'Všichni účastníci musí být členy společného prostoru.'); $event->participants()->syncWithoutDetaching(collect($valid)->reject(fn ($id) => $id === $actor->id)->mapWithKeys(fn ($id) => [$id => ['role' => 'guest', 'response' => 'pending']])->all()); }
    private function syncReminders(CalendarEvent $event, array $reminders, User $actor): void { if (!$reminders) { $event->reminders()->delete(); return; } $event->reminders()->delete(); foreach ($reminders as $reminder) { $recipient = $reminder['user_id'] ?? $actor->id; $this->ensureParticipant($event, $recipient); $event->reminders()->create(['user_id' => $recipient, 'channel' => $reminder['channel'], 'remind_at' => $event->starts_at->copy()->subMinutes((int) $reminder['minutes_before']), 'status' => 'pending']); } }
    private function notifyParticipants(CalendarEvent $event, User $actor, string $type, string $message): void { foreach ($event->participants()->where('users.id', '!=', $actor->id)->get() as $user) $user->notify(new GalleryNotification($type, $message, '/calendar/events/' . $event->uuid, '📅')); }
}
