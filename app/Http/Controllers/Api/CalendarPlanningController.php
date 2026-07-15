<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Album;
use App\Models\EventAttachment;
use App\Models\EventReminder;
use App\Models\EventTask;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Planning\CalendarEventTripService;
use App\Services\Planning\CoupleExperienceRecommendationService;
use App\Services\Planning\DateIdeaLifecycleService;
use App\Services\Planning\DateIdeaTripSyncService;
use App\Services\Planning\CzechPublicHolidayService;
use App\Services\Planning\ExperienceLifecycleService;
use App\Services\Planning\PersonalCelebrationService;
use App\Services\Memories\MemoryEveningService;
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

    public function __construct(
        private readonly CalendarEventTripService $tripService,
        private readonly CzechPublicHolidayService $holidayService,
        private readonly PersonalCelebrationService $personalCelebrationService,
        private readonly CoupleExperienceRecommendationService $experienceRecommendations,
        private readonly ExperienceLifecycleService $experienceLifecycle,
        private readonly MemoryEveningService $memoryEvenings,
        private readonly DateIdeaLifecycleService $dateIdeas,
        private readonly DateIdeaTripSyncService $dateIdeaTripSync,
    ) {}

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
            ->get(['uuid', 'title', 'icon', 'occurred_on', 'gallery_space_id', 'kind', 'person_name', 'relationship', 'is_highlighted'])
            ->flatMap(function ($milestone) use ($from, $to) {
                $occurrences = [];
                for ($year = $from->year; $year <= $to->year; $year++) {
                    $original = Carbon::parse($milestone->occurred_on);
                    $date = Carbon::create($year, $original->month, min($original->day, Carbon::create($year, $original->month, 1)->daysInMonth))->toDateString();
                    if ($date >= $from->toDateString() && $date <= $to->toDateString()) $occurrences[] = [
                        'uuid' => $milestone->uuid,
                        'title' => $milestone->title,
                        'icon' => $milestone->icon,
                        'occurrence_date' => $date,
                        'kind' => $milestone->kind,
                        'person_name' => $milestone->person_name,
                        'relationship' => $milestone->relationship,
                        'is_highlighted' => (bool) $milestone->is_highlighted,
                    ];
                }
                return $occurrences;
            })->values();

        return response()->json([
            'events' => $events->flatMap(fn (CalendarEvent $event) => $this->occurrences($event, $from, $to)),
            'milestones' => $milestones,
            'holidays' => $this->holidayService->between($from, $to),
            'holiday_opportunities' => $this->holidayService->opportunities($from, $to),
            'name_days' => $this->personalCelebrationService->between($from, $to),
            'spaces' => $user->gallerySpaces()->select('gallery_spaces.id', 'name')->get(),
            'trips' => DB::table('trips')->whereIn('gallery_space_id', $this->spaceIds($user))
                ->orderBy('start_date')->get(['id', 'gallery_space_id', 'name', 'start_date', 'end_date', 'budget', 'currency']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $createTrip = $request->boolean('create_trip');
        $data = $this->validatedEvent($request, false);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        $this->validateTripAndAlbum($data, $space->id);

        $event = CalendarEvent::create($data + ['created_by' => $user->id]);
        $this->syncTripSchedule($event);
        $event->participants()->syncWithoutDetaching([$user->id => ['role' => 'owner', 'response' => 'accepted']]);
        $this->syncParticipants($event, $data['participant_ids'] ?? [], $user);
        $this->syncReminders($event, $data['reminders'] ?? [], $user);
        $this->notifyParticipants($event, $user, 'calendar.created', "Nová akce: {$event->title}");
        if ($createTrip && ! $event->trip_id) $this->tripService->createFromEvent($event, $user->id);

        return response()->json($this->eventPayload($event->fresh(), $user), 201);
    }

    /** Turn a verified Czech day-off window into one shared event and trip workspace. */
    public function planHoliday(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);
        $user = $request->user();
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        $start = Carbon::parse($data['start_date'], 'Europe/Prague')->startOfDay();
        $end = Carbon::parse($data['end_date'], 'Europe/Prague')->endOfDay();
        abort_if($start->diffInDays($end->copy()->startOfDay()) > 7, 422, 'Nabídka svátečního volna může mít nejvýše 8 dní.');

        $opportunity = collect($this->holidayService->opportunities($start->copy()->subDays(7), $end->copy()->addDays(7)))
            ->first(fn (array $item) => $item['start_date'] === $start->toDateString() && $item['end_date'] === $end->toDateString());
        abort_unless($opportunity, 422, 'Vybraný termín neodpovídá aktuální nabídce českého svátečního volna.');

        $existing = CalendarEvent::where('gallery_space_id', $space->id)
            ->where('metadata->holiday_opportunity_id', $opportunity['id'])
            ->first();
        if ($existing) {
            return response()->json($this->eventPayload($existing, $user));
        }

        $event = DB::transaction(function () use ($opportunity, $space, $user, $start, $end) {
            $leaveText = $opportunity['leave_days_count']
                ? 'Stačí domluvit ' . $opportunity['leave_days_count'] . ' ' . ($opportunity['leave_days_count'] === 1 ? 'den' : 'dny') . ' volna.'
                : 'Není potřeba čerpat dovolenou.';
            $event = CalendarEvent::create([
                'gallery_space_id' => $space->id,
                'created_by' => $user->id,
                'title' => $opportunity['title'],
                'description' => implode(', ', $opportunity['holiday_titles']) . ". {$leaveText}",
                'type' => 'trip',
                'status' => 'planned',
                'starts_at' => $start,
                'ends_at' => $end,
                'all_day' => true,
                'timezone' => 'Europe/Prague',
                'color' => '#dc2626',
                'is_private' => false,
                'metadata' => [
                    'kind' => 'czech_holiday_trip',
                    'holiday_opportunity_id' => $opportunity['id'],
                    'holiday_dates' => $opportunity['holiday_dates'],
                    'leave_days' => $opportunity['leave_days'],
                    'source' => $opportunity['source'],
                ],
            ]);

            $memberIds = $space->members()->pluck('users.id')->map(fn ($id) => (int) $id)->push($user->id)->unique();
            foreach ($memberIds as $memberId) {
                $isOwner = (int) $memberId === (int) $user->id;
                $event->participants()->attach($memberId, [
                    'role' => $isOwner ? 'owner' : 'guest',
                    'response' => $isOwner ? 'accepted' : 'pending',
                ]);
                $event->reminders()->create([
                    'user_id' => $memberId,
                    'channel' => 'database',
                    'remind_at' => $start->copy()->subWeek(),
                    'status' => 'pending',
                ]);
            }

            $this->tripService->createFromEvent($event, $user->id);
            if ($opportunity['leave_days_count']) {
                DB::table('event_tasks')->insert([
                    'event_id' => $event->id,
                    'title' => 'Domluvit společné volno: ' . implode(', ', $opportunity['leave_days']),
                    'due_at' => $start->copy()->subMonth(),
                    'priority' => 'high',
                    'sort_order' => 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return $event->fresh();
        });

        $this->notifyParticipants($event, $user, 'calendar.holiday_trip', "Nové společné volno: {$event->title}");
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
        $task = $event->tasks()->create($data);
        $this->notifyTaskAssignee($event, $task, $request->user()->id);
        return response()->json($task->load('assignee:id,name'), 201);
    }

    public function updateTask(Request $request, string $uuid, int $taskId): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $task = $event->tasks()->findOrFail($taskId);
        $previousAssignee = $task->assigned_to;
        $data = $request->validate(['title' => 'sometimes|string|max:255', 'notes' => 'nullable|string|max:5000', 'due_at' => 'nullable|date', 'priority' => 'nullable|in:low,normal,high', 'assigned_to' => 'nullable|integer', 'completed' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0']);
        $this->ensureParticipant($event, $data['assigned_to'] ?? null);
        if (array_key_exists('completed', $data)) { $data['completed_at'] = $data['completed'] ? now() : null; unset($data['completed']); }
        $task->update($data);
        if (array_key_exists('assigned_to', $data) && (int) $data['assigned_to'] !== (int) $previousAssignee) $this->notifyTaskAssignee($event, $task, $request->user()->id);
        return response()->json($task->fresh()->load('assignee:id,name'));
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
        $attached = $event->attachments()->whereNotNull('media_item_id')->pluck('media_item_id');
        $query = MediaItem::where('gallery_space_id', $event->gallery_space_id)->whereNull('trashed_at')->whereBetween('taken_at', [$from, $to])->whereNotIn('id', $attached);
        if ($event->latitude !== null && $event->longitude !== null) $query->whereRaw('ABS(latitude - ?) < 0.15 AND ABS(longitude - ?) < 0.15', [$event->latitude, $event->longitude]);
        $candidates = $query->with('variants')->latest('taken_at')->limit(48)->get(['id', 'uuid', 'display_title', 'original_filename', 'media_type', 'taken_at'])
            ->map(fn (MediaItem $media) => ['id' => $media->id, 'uuid' => $media->uuid, 'display_title' => $media->display_title, 'original_filename' => $media->original_filename, 'media_type' => $media->media_type, 'taken_at' => $media->taken_at?->toIso8601String(), 'thumbnail_url' => $media->thumbnail_url]);
        return response()->json(['candidates' => $candidates]);
    }

    public function applyMediaSuggestions(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        $data = $request->validate(['media_ids' => 'required|array|min:1|max:48', 'media_ids.*' => 'integer']);
        $media = MediaItem::where('gallery_space_id', $event->gallery_space_id)->whereNull('trashed_at')->whereIn('id', $data['media_ids'])->get();
        if ($media->count() !== count(array_unique($data['media_ids']))) abort(422, 'Některá vybraná média nejsou dostupná.');
        foreach ($media as $item) $event->attachments()->firstOrCreate(['media_item_id' => $item->id], ['kind' => 'memory']);
        if ($event->album_id) $this->syncExperienceAlbum($event, $request->user()->id, $media);
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
        $album = $this->syncExperienceAlbum($event, $request->user()->id, $media);
        $experience = $this->experienceLifecycle->complete($event, $memoryId, $album, $media, $request->user());
        $this->dateIdeas->completeEvent($event->fresh());
        $memory = (array) DB::table('shared_memory_moments')->find($memoryId);
        $memory['album'] = ['uuid' => $album->uuid, 'title' => $album->title];
        $memory['experience'] = $experience;
        return response()->json($memory, 201);
    }

    /** A short reflection keeps an ordinary event connected to future plans. */
    public function reflection(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid);
        if (!Schema::hasTable('calendar_event_reflections')) return response()->json(['message' => 'Pro společné ohlédnutí dokončete migrace aplikace.'], 503);
        $reflection = DB::table('calendar_event_reflections')->where('calendar_event_id', $event->id)->first();
        return response()->json(['reflection' => $reflection, 'has_shared_memory' => DB::table('shared_memory_moments')->where('calendar_event_id', $event->id)->exists(), 'album_id' => $event->album_id]);
    }

    public function updateReflection(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        if (!Schema::hasTable('calendar_event_reflections')) return response()->json(['message' => 'Pro společné ohlédnutí dokončete migrace aplikace.'], 503);
        abort_if($event->starts_at->isFuture(), 422, 'Ohlédnutí lze uložit až po začátku akce.');
        $data = $request->validate(['rating' => 'nullable|integer|between:1,5', 'mood' => 'nullable|in:joyful,calm,adventurous,cozy', 'highlight' => 'nullable|string|max:2000', 'next_time' => 'nullable|string|max:2000']);
        $existing = DB::table('calendar_event_reflections')->where('calendar_event_id', $event->id)->first();
        $row = $data + ['gallery_space_id' => $event->gallery_space_id, 'updated_by' => $request->user()->id, 'updated_at' => now()];
        if ($existing) { DB::table('calendar_event_reflections')->where('id', $existing->id)->update($row); $id = $existing->id; }
        else $id = DB::table('calendar_event_reflections')->insertGetId($row + ['uuid' => (string) Str::uuid(), 'calendar_event_id' => $event->id, 'created_by' => $request->user()->id, 'created_at' => now()]);
        $this->dateIdeas->recordEventReflection($event, $request->user(), $data);
        return response()->json(DB::table('calendar_event_reflections')->find($id), $existing ? 200 : 201);
    }

    /** Turn a positive shared experience into one new, independently editable plan. */
    public function scheduleRevisit(Request $request, string $uuid): JsonResponse
    {
        $source = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($source, $request->user());
        $data = $request->validate(['starts_at' => 'required|date|after:now', 'reminder_minutes' => 'nullable|integer|min:0|max:525600', 'title' => 'nullable|string|max:160']);
        $startsAt = Carbon::parse($data['starts_at']);
        $existing = CalendarEvent::where('gallery_space_id', $source->gallery_space_id)
            ->where('starts_at', $startsAt)->where('metadata->source_event_uuid', $source->uuid)->first();
        if ($existing) return response()->json($this->eventPayload($existing, $request->user()));

        $reflection = Schema::hasTable('calendar_event_reflections')
            ? DB::table('calendar_event_reflections')->where('calendar_event_id', $source->id)->first() : null;
        $metadata = ['kind' => 'event_revisit', 'source_event_uuid' => $source->uuid];
        $event = CalendarEvent::create([
            'gallery_space_id' => $source->gallery_space_id, 'created_by' => $request->user()->id,
            'title' => $data['title'] ?? "Znovu spolu: {$source->title}",
            'description' => $reflection?->highlight ? "Navazuje na společný zážitek: {$reflection->highlight}" : "Navazuje na váš společný zážitek „{$source->title}“.",
            'type' => $source->type === 'trip' ? 'outing' : $source->type, 'status' => 'planned',
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addSeconds(($source->ends_at ?? $source->starts_at)->diffInSeconds($source->starts_at) ?: 7200),
            'timezone' => $source->timezone ?: 'Europe/Prague', 'place_name' => $source->place_name,
            'latitude' => $source->latitude, 'longitude' => $source->longitude, 'color' => $source->color ?: '#ec4899',
            'is_private' => false, 'metadata' => $metadata,
        ]);
        $members = $source->participants()->pluck('users.id')->all();
        if (!$members) $members = DB::table('gallery_space_user')->where('gallery_space_id', $source->gallery_space_id)->pluck('user_id')->all();
        foreach ($members as $memberId) {
            $event->participants()->syncWithoutDetaching([(int) $memberId => ['role' => (int) $memberId === $request->user()->id ? 'owner' : 'guest', 'response' => (int) $memberId === $request->user()->id ? 'accepted' : 'pending']]);
            $event->reminders()->create(['user_id' => $memberId, 'channel' => 'database', 'remind_at' => $startsAt->copy()->subMinutes((int) ($data['reminder_minutes'] ?? 10080)), 'status' => 'pending']);
        }
        return response()->json($this->eventPayload($event->fresh(), $request->user()), 201);
    }

    /** Promote an ordinary shared calendar event into a trip workspace exactly once. */
    public function createTrip(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid); $this->ensureCanEdit($event, $request->user());
        [$trip, $created] = $this->tripService->createFromEvent($event, $request->user()->id);
        $event->refresh();
        $sync = $this->dateIdeaTripSync->syncForEvent($event, (int) $trip->id, $request->user());
        if ($sync !== null) $trip->date_idea_sync = $sync;
        return response()->json($trip, $created ? 201 : 200);
    }

    public function story(Request $request, string $uuid): JsonResponse
    {
        $event = $this->findVisibleEvent($request->user(), $uuid);
        $this->ensureCanEdit($event, $request->user());

        $media = MediaItem::query()
            ->whereIn('id', $event->attachments()->whereNotNull('media_item_id')->pluck('media_item_id'))
            ->where('gallery_space_id', $event->gallery_space_id)
            ->whereNull('deleted_at')
            ->orderBy('taken_at')
            ->get(['id', 'uuid', 'media_type']);
        $reflection = Schema::hasTable('calendar_event_reflections')
            ? DB::table('calendar_event_reflections')->where('calendar_event_id', $event->id)->first()
            : null;
        $memoryMomentId = DB::table('shared_memory_moments')->where('calendar_event_id', $event->id)->value('id');
        $partnerPerspectives = $memoryMomentId && Schema::hasTable('shared_memory_reflections')
            ? DB::table('shared_memory_reflections as reflection')->join('users', 'users.id', '=', 'reflection.user_id')
                ->where('reflection.shared_memory_moment_id', $memoryMomentId)->orderBy('reflection.created_at')
                ->get(['users.name', 'reflection.mood', 'reflection.note'])
            : collect();
        $summary = $event->starts_at->locale('cs')->translatedFormat('j. F Y')
            . ' · ' . ($event->place_name ?: 'společný zážitek')
            . ' · ' . $media->count() . ' vzpomínek';

        DB::transaction(function () use ($event, $media, $reflection, $partnerPerspectives, $summary, $request) {
            DB::table('event_stories')->updateOrInsert(
                ['event_id' => $event->id],
                ['summary' => $summary, 'media_ids' => json_encode($media->pluck('id')->all()), 'generated_at' => now(), 'updated_at' => now(), 'created_at' => now()]
            );

            $album = $event->album_id
                ? Album::query()->whereKey($event->album_id)->where('gallery_space_id', $event->gallery_space_id)->first()
                : null;
            if (!$album || !Schema::hasTable('album_story_blocks')) return;

            $text = $reflection?->highlight ?: $summary;
            if ($partnerPerspectives->isNotEmpty()) {
                $moodLabels = ['joyful' => 'radostně', 'calm' => 'v klidu', 'adventurous' => 'dobrodružně', 'cozy' => 'pohodově', 'grateful' => 'vděčně', 'funny' => 'vesele'];
                $perspectiveText = $partnerPerspectives->map(function ($perspective) use ($moodLabels) {
                    $detail = collect([$perspective->note, $moodLabels[$perspective->mood] ?? null])->filter()->implode(' · ');
                    return $detail ? "{$perspective->name}: {$detail}" : null;
                })->filter()->implode("\n");
                if ($perspectiveText) $text .= "\n\nNaše pohledy:\n{$perspectiveText}";
            }
            $blocks = [
                ['type' => 'heading', 'content' => ['text' => $event->title, 'level' => 1]],
                ['type' => 'text', 'content' => ['text' => $text]],
            ];
            if ($event->latitude !== null && $event->longitude !== null) {
                $blocks[] = ['type' => 'map', 'content' => ['latitude' => (float) $event->latitude, 'longitude' => (float) $event->longitude, 'label' => $event->place_name ?: $event->title, 'zoom' => 14]];
            }
            $photoUuids = $media->where('media_type', 'photo')->pluck('uuid')->values()->all();
            if ($photoUuids) $blocks[] = ['type' => 'photo', 'content' => ['media_uuids' => $photoUuids, 'layout' => count($photoUuids) > 1 ? 'grid2' : 'single']];
            $videoUuid = $media->firstWhere('media_type', 'video')?->uuid;
            if ($videoUuid) $blocks[] = ['type' => 'video', 'content' => ['media_uuid' => $videoUuid]];

            $existing = DB::table('album_story_blocks')->where('album_id', $album->id)->orderBy('sort_order')->get();
            $generated = $existing->filter(function ($block) use ($event) {
                $content = is_string($block->content) ? json_decode($block->content, true) : $block->content;
                return is_array($content) && ($content['source_event_id'] ?? null) === $event->id;
            });
            $byType = $generated->keyBy('type');
            $baseOrder = $generated->min('sort_order');
            if ($baseOrder === null) $baseOrder = ((int) ($existing->max('sort_order') ?? -1)) + 1;
            $types = collect($blocks)->pluck('type')->all();
            foreach ($generated->whereNotIn('type', $types) as $block) DB::table('album_story_blocks')->where('id', $block->id)->delete();

            foreach ($blocks as $position => $block) {
                $content = $block['content'] + ['generated' => true, 'source_event_id' => $event->id];
                $row = ['content' => json_encode($content), 'sort_order' => $baseOrder + $position, 'updated_at' => now()];
                if ($existingBlock = $byType->get($block['type'])) {
                    DB::table('album_story_blocks')->where('id', $existingBlock->id)->update($row);
                } else {
                    DB::table('album_story_blocks')->insert($row + ['album_id' => $album->id, 'created_by' => $request->user()->id, 'type' => $block['type'], 'created_at' => now()]);
                }
            }
        });

        $album = $event->album_id
            ? Album::query()->whereKey($event->album_id)->where('gallery_space_id', $event->gallery_space_id)->first(['uuid', 'title'])
            : null;
        $story = DB::table('event_stories')->where('event_id', $event->id)->first();
        $story->album = $album ? ['uuid' => $album->uuid, 'title' => $album->title] : null;
        $story->story_blocks = $album && Schema::hasTable('album_story_blocks')
            ? DB::table('album_story_blocks')->where('album_id', $event->album_id)->get()->filter(function ($block) use ($event) {
                $content = is_string($block->content) ? json_decode($block->content, true) : $block->content;
                return is_array($content) && ($content['source_event_id'] ?? null) === $event->id;
            })->count()
            : 0;
        return response()->json($story);
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
        $state = ! empty($data['trip_id']) || ! empty($data['event_id']) ? 'assigned' : 'inbox';
        $id = DB::table('travel_inbox_items')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'added_by' => $user->id, 'state' => $state, 'created_at' => now(), 'updated_at' => now()]);
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
        $expense = DB::table('trip_expenses')->where('trip_id', $tripId)->where('id', $expenseId)->first();
        abort_unless($expense, 404);
        abort_if(($expense->automation_source ?? null) === 'bank_transaction', 409,
            'Bankovní výdaj upravte nebo vyřaďte v Revolut přehledu cesty, aby zůstala zachována historie.');
        DB::table('trip_expenses')->where('id', $expenseId)->delete();
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
        $data = $request->validate(['event_uuid' => 'nullable|uuid']);
        $user = $request->user();
        $query = DB::table('time_capsules')->whereIn('gallery_space_id', $this->spaceIds($user));
        if (empty($data['event_uuid'])) return response()->json($query->where('created_by', $user->id)->orderBy('deliver_at')->get());

        $event = $this->findVisibleEvent($user, $data['event_uuid']);
        return response()->json($query->where('event_id', $event->id)
            ->where(function ($visibility) use ($user) {
                $visibility->where('created_by', $user->id)
                    ->orWhere(function ($recipient) use ($user) {
                        $recipient->where('recipient_user_id', $user->id)->where('status', 'delivered');
                    });
            })
            ->orderBy('deliver_at')->get());
    }

    public function storeTimeCapsule(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'recipient_user_id' => 'nullable|integer', 'event_id' => 'nullable|integer', 'media_item_id' => 'nullable|integer', 'title' => 'required|string|max:255', 'message' => 'nullable|string|max:10000', 'deliver_at' => 'required|date|after:now']);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        if (!empty($data['recipient_user_id']) && !$space->members()->whereKey($data['recipient_user_id'])->exists() && $space->owner_id !== (int) $data['recipient_user_id']) abort(422, 'Příjemce není členem prostoru.');
        if (!empty($data['event_id'])) {
            $event = $this->findVisibleEvent($user, CalendarEvent::where('id', $data['event_id'])->where('gallery_space_id', $space->id)->value('uuid') ?? '');
            abort_unless($event->gallery_space_id === $space->id, 422, 'Akce musí patřit do stejného společného prostoru.');
        }
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
            'reminder_minutes' => 'nullable|integer|min:0|max:525600',
        ]);
        $space = $this->ownedSpace($user, (int) $data['gallery_space_id']);
        $moments = DB::table('shared_memory_moments')->where('gallery_space_id', $space->id)->whereIn('uuid', $data['moment_uuids'])->get();
        abort_unless($moments->count() === count($data['moment_uuids']), 422, 'Některé společné vzpomínky nejsou dostupné.');

        $mediaIds = $moments->flatMap(fn ($moment) => json_decode($moment->media_item_ids ?: '[]', true) ?: [])->filter(fn ($id) => is_numeric($id))->unique()->values();
        $media = MediaItem::where('gallery_space_id', $space->id)->whereNull('trashed_at')->where('is_hidden', false)->whereIn('id', $mediaIds)->limit(30)->get();
        if ($media->isEmpty()) {
            $startsAt = Carbon::parse($data['scheduled_at']);
            $existing = CalendarEvent::query()->where('gallery_space_id', $space->id)->where('starts_at', $startsAt)->where('metadata->memory_evening', true)->first();
            if ($existing) return response()->json($this->eventPayload($existing, $user));
            $event = CalendarEvent::create(['gallery_space_id' => $space->id, 'created_by' => $user->id, 'title' => $data['title'] ?? 'Večer se vzpomínkami',
                'description' => 'Společný návrat k zážitkům: ' . $moments->pluck('title')->implode(' · '), 'type' => 'event', 'status' => 'planned',
                'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHours(2), 'timezone' => 'Europe/Prague', 'color' => '#ec4899',
                'metadata' => ['memory_moment_uuids' => $moments->pluck('uuid')->values()->all(), 'memory_evening' => true]]);
            $memberIds = $space->members()->pluck('users.id')->push($user->id)->unique()->values();
            $event->participants()->sync($memberIds->mapWithKeys(fn (int $id) => [$id => ['role' => $id === $user->id ? 'owner' : 'guest', 'response' => $id === $user->id ? 'accepted' : 'pending']])->all());
            foreach ($memberIds as $memberId) $event->reminders()->create(['user_id' => $memberId, 'channel' => 'database', 'remind_at' => $startsAt->copy()->subMinutes((int) ($data['reminder_minutes'] ?? 1440)), 'status' => 'pending']);
            return response()->json($this->eventPayload($event->fresh(), $user), 201);
        }
        $evening = $this->memoryEvenings->schedule($space, $user, [
            'fingerprint' => hash('sha256', $moments->pluck('uuid')->sort()->implode('|')),
            'source_type' => 'favorite_flashback', 'title' => $data['title'] ?? 'Večer se vzpomínkami',
            'description' => 'Společný návrat k zážitkům: ' . $moments->pluck('title')->implode(' · '),
            'source_happened_on' => $moments->pluck('happened_on')->filter()->sort()->first(),
            'source_moment_uuids' => $moments->pluck('uuid')->values()->all(),
            'scheduled_for' => $data['scheduled_at'], 'repeat_annually' => false,
        ], $media);
        return response()->json($this->eventPayload($evening->event()->firstOrFail(), $user), $evening->wasRecentlyCreated ? 201 : 200);
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
        $ideas = $this->experienceRecommendations->recommend($space, ['theme' => $theme, 'date' => $data['date'] ?? null], 20);
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
        $payload['album'] = $event->album_id
            ? Album::query()->whereKey($event->album_id)->where('gallery_space_id', $event->gallery_space_id)->first(['uuid', 'title'])
            : null;
        $payload['origin'] = $this->originPayload($event, $viewer);
        $payload['experience'] = $this->experienceLifecycle->status($event, $viewer);
        $payload['date_idea'] = $this->dateIdeas->forEvent($event, $viewer);
        $payload['planning_items'] = Schema::hasTable('travel_inbox_items')
            ? DB::table('travel_inbox_items')->where('gallery_space_id', $event->gallery_space_id)
                ->where('state', '!=', 'archived')
                ->where(function ($query) use ($event) {
                    $query->where('event_id', $event->id);
                    if ($event->trip_id) $query->orWhere('trip_id', $event->trip_id);
                })
                ->latest('updated_at')->limit(12)
                ->get(['uuid', 'title', 'notes', 'source_url', 'kind', 'state', 'event_id', 'trip_id'])
            : [];
        return $payload;
    }

    /**
     * Calendar actions can be born from a photo, milestone or earlier event.
     * Return that small, permission-checked trace so the couple never loses
     * the reason a plan exists while moving between gallery and calendar.
     */
    private function originPayload(CalendarEvent $event, ?User $viewer): ?array
    {
        $metadata = $event->metadata ?? [];
        if (! is_array($metadata)) return null;
        $kind = $metadata['kind'] ?? (! empty($metadata['memory_evening']) ? 'memory_evening' : null);
        if (! $kind) return null;

        if ($kind === 'media_revisit' && ! empty($metadata['source_media_uuid'])) {
            return [
                'kind' => $kind,
                'label' => 'Návrat k místu z galerie',
                'media' => $this->originMediaPayload($metadata['source_media_uuid'], $event->gallery_space_id),
            ];
        }

        if (in_array($kind, ['milestone_celebration', 'birthday_celebration'], true) && ! empty($metadata['source_milestone_uuid'])) {
            $milestone = DB::table('relationship_milestones')
                ->where('uuid', $metadata['source_milestone_uuid'])
                ->where('gallery_space_id', $event->gallery_space_id)
                ->when($viewer, fn ($query) => $query->where(fn ($visible) => $visible->where('visibility', 'shared')->orWhere('created_by', $viewer->id)))
                ->first(['uuid', 'title', 'icon', 'occurred_on', 'media_item_id']);
            if (! $milestone) return null;

            $media = $milestone->media_item_id
                ? $this->originMediaPayloadById((int) $milestone->media_item_id, $event->gallery_space_id)
                : null;
            return [
                'kind' => $kind,
                'label' => $kind === 'birthday_celebration' ? 'Oslava narozenin blízkého' : 'Oslava společného milníku',
                'milestone' => ['uuid' => $milestone->uuid, 'title' => $milestone->title, 'icon' => $milestone->icon, 'occurred_on' => $milestone->occurred_on],
                'media' => $media,
            ];
        }

        if ($kind === 'event_revisit' && ! empty($metadata['source_event_uuid'])) {
            $source = $this->visibleEvents($viewer ?? $event->creator)->where('uuid', $metadata['source_event_uuid'])->first();
            if (! $source) return null;
            return ['kind' => $kind, 'label' => 'Návrat k dřívější akci', 'event' => ['uuid' => $source->uuid, 'title' => $source->title]];
        }

        if (in_array($kind, ['recipe_cooking', 'trip_recipe_meal'], true) && ! empty($metadata['recipe_uuid']) && Schema::hasTable('recipes')) {
            $recipe = DB::table('recipes')->where('uuid', $metadata['recipe_uuid'])->where('gallery_space_id', $event->gallery_space_id)->whereNull('deleted_at')->first(['uuid', 'title', 'category', 'album_id']);
            if (! $recipe) return null;
            return [
                'kind' => $kind,
                'label' => $kind === 'trip_recipe_meal' ? 'Jídlo propojené s plánem cesty' : 'Společné vaření z vaší kuchařky',
                'recipe' => ['uuid' => $recipe->uuid, 'title' => $recipe->title, 'category' => $recipe->category],
            ];
        }

        if (in_array($kind, ['place_recommendation_outing', 'saved_place_outing'], true) && !empty($metadata['place_id'])) {
            $place = Place::query()->whereKey((int) $metadata['place_id'])->where('gallery_space_id', $event->gallery_space_id)->first(['id', 'name', 'type', 'city', 'country']);
            if (!$place) return null;
            return [
                'kind' => $kind,
                'label' => $kind === 'place_recommendation_outing' ? 'Doporučeno z vašich společných hodnocení' : 'Naplánováno z uloženého místa',
                'places' => [[
                    'id' => $place->id, 'name' => $place->name, 'type' => $place->type,
                    'city' => $place->city, 'country' => $place->country,
                ]],
            ];
        }

        if ($kind === 'memory_evening' && empty($metadata['memory_moment_uuids']) && ! empty($metadata['memory_evening_uuid']) && Schema::hasTable('memory_evenings')) {
            $evening = DB::table('memory_evenings')->where('uuid', $metadata['memory_evening_uuid'])->where('gallery_space_id', $event->gallery_space_id)->first(['uuid', 'title', 'status']);
            if (! $evening) return null;
            return ['kind' => 'memory_evening', 'label' => 'Partnerský večer z vaší galerie', 'memory_evening' => ['uuid' => $evening->uuid, 'title' => $evening->title, 'status' => $evening->status]];
        }

        if ($kind === 'place_selection_outing' && ! empty($metadata['place_ids']) && is_array($metadata['place_ids'])) {
            $placeIds = collect($metadata['place_ids'])->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values();
            $places = Place::query()
                ->where('gallery_space_id', $event->gallery_space_id)
                ->whereIn('id', $placeIds)
                ->get(['id', 'name', 'type', 'city', 'country'])
                ->keyBy('id');
            $ordered = $placeIds->map(fn ($id) => $places->get($id))
                ->filter()
                ->map(fn (Place $place) => [
                    'id' => $place->id,
                    'name' => $place->name,
                    'type' => $place->type,
                    'city' => $place->city,
                    'country' => $place->country,
                ])->values();
            return ['kind' => $kind, 'label' => 'Výlet sestavený z vašich uložených míst', 'places' => $ordered];
        }

        if (! empty($metadata['memory_evening']) && ! empty($metadata['memory_moment_uuids']) && is_array($metadata['memory_moment_uuids'])) {
            $moments = DB::table('shared_memory_moments')
                ->where('gallery_space_id', $event->gallery_space_id)
                ->whereIn('uuid', $metadata['memory_moment_uuids'])
                ->get(['uuid', 'title', 'happened_on', 'media_item_ids'])
                ->keyBy('uuid');
            $mediaIds = $moments->flatMap(fn ($moment) => json_decode($moment->media_item_ids ?: '[]', true) ?: [])->filter(fn ($id) => is_numeric($id))->unique();
            $media = MediaItem::query()->whereIn('id', $mediaIds)->where('gallery_space_id', $event->gallery_space_id)->whereNull('trashed_at')->where('is_hidden', false)->with('variants')->get()->keyBy('id');
            $ordered = collect($metadata['memory_moment_uuids'])->map(function ($uuid) use ($moments, $media) {
                $moment = $moments->get($uuid);
                if (! $moment) return null;
                $firstMediaId = collect(json_decode($moment->media_item_ids ?: '[]', true) ?: [])->first(fn ($id) => $media->has((int) $id));
                $cover = $firstMediaId ? $media->get((int) $firstMediaId) : null;
                return [
                    'uuid' => $moment->uuid,
                    'title' => $moment->title,
                    'happened_on' => $moment->happened_on,
                    'media' => $cover ? $this->mediaOriginData($cover) : null,
                ];
            })->filter()->values();
            return ['kind' => 'memory_evening', 'label' => 'Večer z vašich společných vzpomínek', 'moments' => $ordered];
        }

        return null;
    }

    private function originMediaPayload(string $uuid, int $spaceId): ?array
    {
        $media = MediaItem::query()->where('uuid', $uuid)->where('gallery_space_id', $spaceId)->whereNull('trashed_at')->where('is_hidden', false)->with('variants')->first();
        return $media ? $this->mediaOriginData($media) : null;
    }

    private function originMediaPayloadById(int $id, int $spaceId): ?array
    {
        $media = MediaItem::query()->whereKey($id)->where('gallery_space_id', $spaceId)->whereNull('trashed_at')->where('is_hidden', false)->with('variants')->first();
        return $media ? $this->mediaOriginData($media) : null;
    }

    private function mediaOriginData(MediaItem $media): array
    {
        return [
            'uuid' => $media->uuid,
            'title' => $media->display_title ?: $media->original_filename,
            'thumbnail_url' => $media->thumbnail_url,
            'media_type' => $media->media_type,
        ];
    }

    /**
     * A completed event, its selected media and its memory must be one shared
     * experience rather than three disconnected records. The album is created
     * only once and later media selections are safely merged into it.
     */
    private function syncExperienceAlbum(CalendarEvent $event, int $actorId, \Illuminate\Support\Collection $media): Album
    {
        $album = $event->album_id
            ? Album::where('id', $event->album_id)->where('gallery_space_id', $event->gallery_space_id)->first()
            : null;

        if (!$album) {
            $album = Album::create([
                'gallery_space_id' => $event->gallery_space_id,
                'title' => $event->title,
                'slug' => Str::slug($event->title),
                'description' => $event->description,
                'event_date_start' => $event->starts_at->toDateString(),
                'event_date_end' => ($event->ends_at ?? $event->starts_at)->toDateString(),
                'event_mode' => true,
                'event_start_at' => $event->starts_at,
                'event_end_at' => $event->ends_at,
                'event_place_name' => $event->place_name,
                'event_latitude' => $event->latitude,
                'event_longitude' => $event->longitude,
                'location_name' => $event->place_name,
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
                'visibility' => 'shared',
                'sort_mode' => 'date_taken',
                'sort_direction' => 'asc',
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'sync_status' => 'pending',
            ]);
            $album->rebuildPaths();
            $event->update(['album_id' => $album->id]);
        }

        if ($media->isNotEmpty()) {
            $pivot = $media->values()->mapWithKeys(fn (MediaItem $item, int $position) => [$item->id => [
                'sort_order' => $position,
                'added_at' => now(),
                'added_by' => $actorId,
            ]])->all();
            $album->media()->syncWithoutDetaching($pivot);
            MediaItem::whereIn('id', $media->pluck('id'))->whereNull('primary_album_id')->update(['primary_album_id' => $album->id]);
        }

        $albumMedia = $album->media()->get(['media_items.id', 'media_items.size_bytes']);
        $album->update([
            'cover_media_id' => $album->cover_media_id ?: $albumMedia->first()?->id,
            'media_count' => $albumMedia->count(),
            'total_size_bytes' => (int) $albumMedia->sum('size_bytes'),
            'updated_by' => $actorId,
        ]);

        return $album->fresh();
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
    private function notifyTaskAssignee(CalendarEvent $event, EventTask $task, int $actorId): void { if (! $task->assigned_to || $task->assigned_to === $actorId) return; User::find($task->assigned_to)?->notify(new GalleryNotification('calendar.task.assigned', "Nový společný úkol: {$task->title} ({$event->title})", '/calendar/events/' . $event->uuid, '✅')); }
    private function findTrip(User $user, int $id): object { return DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $this->spaceIds($user))->firstOrFail(); }
    private function validateTripAndAlbum(array $data, int $spaceId): void { if (!empty($data['trip_id'])) DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); if (!empty($data['album_id'])) DB::table('albums')->where('id', $data['album_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); }
    private function syncTripSchedule(CalendarEvent $event): void { if (!$event->trip_id) return; DB::table('trips')->where('id', $event->trip_id)->where('gallery_space_id', $event->gallery_space_id)->update(['start_date' => $event->starts_at->toDateString(), 'end_date' => ($event->ends_at ?? $event->starts_at)->toDateString(), 'updated_at' => now()]); }
    private function validateInboxLinks(array $data, int $spaceId): array { if (!empty($data['trip_id'])) DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); if (!empty($data['event_id'])) CalendarEvent::where('id', $data['event_id'])->where('gallery_space_id', $spaceId)->firstOrFail(); $day = !empty($data['trip_day_id']) ? DB::table('trip_days as d')->join('trips as t', 't.id', '=', 'd.trip_id')->where('d.id', $data['trip_day_id'])->where('t.gallery_space_id', $spaceId)->select('d.trip_id')->firstOrFail() : null; if ($day && !empty($data['trip_id']) && (int) $data['trip_id'] !== (int) $day->trip_id) abort(422, 'Den itineráře musí patřit ke zvolené cestě.'); if ($day && empty($data['trip_id'])) $data['trip_id'] = $day->trip_id; if (!empty($data['trip_activity_id'])) { $activity = DB::table('trip_activities as a')->join('trip_days as d', 'd.id', '=', 'a.trip_day_id')->join('trips as t', 't.id', '=', 'd.trip_id')->where('a.id', $data['trip_activity_id'])->where('t.gallery_space_id', $spaceId)->select('a.trip_day_id', 'd.trip_id')->firstOrFail(); if ($day && (int) $activity->trip_day_id !== (int) $data['trip_day_id']) abort(422, 'Aktivita nepatří do vybraného dne.'); if (!empty($data['trip_id']) && (int) $activity->trip_id !== (int) $data['trip_id']) abort(422, 'Aktivita nepatří ke zvolené cestě.'); if (empty($data['trip_id'])) $data['trip_id'] = $activity->trip_id; } return $data; }
    private function intersectTimeRanges(array $left, array $right): array { $result = []; foreach ($left as $a) foreach ($right as $b) { $start = $a['start']->greaterThan($b['start']) ? $a['start']->copy() : $b['start']->copy(); $end = $a['end']->lessThan($b['end']) ? $a['end']->copy() : $b['end']->copy(); if ($start->lt($end)) $result[] = ['start' => $start, 'end' => $end]; } return $result; }
    private function syncParticipants(CalendarEvent $event, array $ids, User $actor): void { $valid = User::whereIn('id', $ids)->whereHas('gallerySpaces', fn (Builder $q) => $q->where('gallery_spaces.id', $event->gallery_space_id))->pluck('id')->all(); if (count(array_unique($ids)) !== count($valid)) abort(422, 'Všichni účastníci musí být členy společného prostoru.'); $event->participants()->syncWithoutDetaching(collect($valid)->reject(fn ($id) => $id === $actor->id)->mapWithKeys(fn ($id) => [$id => ['role' => 'guest', 'response' => 'pending']])->all()); }
    private function syncReminders(CalendarEvent $event, array $reminders, User $actor): void { if (!$reminders) { $event->reminders()->delete(); return; } $event->reminders()->delete(); foreach ($reminders as $reminder) { $recipient = $reminder['user_id'] ?? $actor->id; $this->ensureParticipant($event, $recipient); $event->reminders()->create(['user_id' => $recipient, 'channel' => $reminder['channel'], 'remind_at' => $event->starts_at->copy()->subMinutes((int) $reminder['minutes_before']), 'status' => 'pending']); } }
    private function notifyParticipants(CalendarEvent $event, User $actor, string $type, string $message): void { foreach ($event->participants()->where('users.id', '!=', $actor->id)->get() as $user) $user->notify(new GalleryNotification($type, $message, '/calendar/events/' . $event->uuid, '📅')); }
}
