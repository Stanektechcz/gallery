<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\MediaItem;
use App\Services\Planning\TripPreparationTimelineService;
use App\Services\Media\AlbumCurationAssistantService;
use App\Services\Travel\TransportSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TripController extends Controller
{
    public function __construct(
        private readonly TransportSearchService $transportSearch,
        private readonly TripPreparationTimelineService $tripPreparation,
        private readonly AlbumCurationAssistantService $albumCuration,
    ) {}

    // ─── Trips CRUD ────────────────────────────────────────────────────────

    /**
     * GET /api/v1/trips
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $trips = DB::table('trips')
            ->where('gallery_space_id', $space->id)
            ->orderByDesc('start_date')
            ->get();

        return response()->json($trips->map(fn($t) => $this->enrichTrip($t)));
    }

    /**
     * POST /api/v1/trips
     */
    public function store(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $v = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'notes'       => 'nullable|string|max:10000',
            'status'      => 'nullable|in:draft,planned,active,completed,archived',
            'timezone'    => 'nullable|timezone',
            'budget'      => 'nullable|numeric|min:0',
            'currency'    => 'nullable|string|size:3',
        ]);

        $id = DB::table('trips')->insertGetId([
            'gallery_space_id' => $space->id,
            'created_by'       => $user->id,
            'name'             => $v['name'],
            'description'      => $v['description'] ?? null,
            'start_date'       => $v['start_date'],
            'end_date'         => $v['end_date'],
            'notes'            => $v['notes'] ?? null,
            'status'           => $v['status'] ?? 'draft',
            'timezone'         => $v['timezone'] ?? null,
            'budget'           => $v['budget'] ?? null,
            'currency'         => strtoupper($v['currency'] ?? 'CZK'),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json($this->enrichTrip(DB::table('trips')->find($id)), 201);
    }

    /**
     * GET /api/v1/trips/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->first();
        if (! $trip) {
            return response()->json(['error' => 'not found'], 404);
        }

        return response()->json($this->enrichTrip($trip));
    }

    /**
     * PATCH /api/v1/trips/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $v = $request->validate([
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
            'notes'       => 'nullable|string|max:10000',
            'status'      => 'nullable|in:draft,planned,active,completed,archived',
            'timezone'    => 'nullable|timezone',
            'budget'      => 'nullable|numeric|min:0',
            'currency'    => 'nullable|string|size:3',
            'is_offline_available' => 'nullable|boolean',
        ]);

        $toUpdate = array_filter($v, fn($val) => $val !== null);
        $toUpdate['updated_at'] = now();

        DB::table('trips')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->update($toUpdate);

        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->first();
        if (! $trip) {
            return response()->json(['error' => 'not found'], 404);
        }

        if (array_key_exists('start_date', $v) || array_key_exists('end_date', $v)) {
            foreach (DB::table('calendar_events')->where('trip_id', $trip->id)->get() as $event) {
                $startsAt = Carbon::parse($event->starts_at)->setDateFrom(Carbon::parse($trip->start_date));
                $updateEvent = ['starts_at' => $startsAt, 'updated_at' => now()];
                if ($event->ends_at) $updateEvent['ends_at'] = Carbon::parse($event->ends_at)->setDateFrom(Carbon::parse($trip->end_date));
                DB::table('calendar_events')->where('id', $event->id)->update($updateEvent);
            }
        }
        if ($this->tripPreparation->canSync()) $this->tripPreparation->sync($trip);

        return response()->json($this->enrichTrip($trip));
    }

    /**
     * DELETE /api/v1/trips/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        DB::table('trips')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->delete();

        return response()->json(['status' => 'deleted']);
    }

    // ─── Media ─────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/trips/{id}/media
     */
    public function media(Request $request, int $id): JsonResponse
    {
        try {
            $user  = $request->user();
            $space = $user->gallerySpaces()->first();

            if (! $this->tripBelongsToSpace($id, $space->id)) {
                return response()->json(['error' => 'not found'], 404);
            }

            $mediaIds = DB::table('trip_media')->where('trip_id', $id)->pluck('media_item_id');

            $items = MediaItem::with('variants')
                ->whereIn('id', $mediaIds)
                ->whereNull('trashed_at')
                ->orderBy('taken_at')
                ->get()
                ->map(fn($p) => [
                    'id'            => $p->id,
                    'uuid'          => $p->uuid,
                    'file_name'     => $p->file_name,
                    'media_type'    => $p->media_type,
                    'thumbnail_url' => $p->thumbnail_url,
                    'taken_at'      => $p->taken_at,
                    'latitude'      => $p->latitude,
                    'longitude'     => $p->longitude,
                ]);

            return response()->json($items);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('TripController::media failed: ' . $e->getMessage());
            return response()->json([]);
        }
    }

    /**
     * GET /api/v1/trips/{id}/suggest-media
     * Find media in the trip date range not yet linked.
     */
    public function suggestMedia(Request $request, int $id): JsonResponse
    {
        try {
            $user  = $request->user();
            $space = $user->gallerySpaces()->first();

            $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->first();
            if (! $trip) {
                return response()->json(['error' => 'not found'], 404);
            }

            $alreadyLinked = DB::table('trip_media')->where('trip_id', $id)->pluck('media_item_id');

            $suggested = MediaItem::with('variants')
                ->where('gallery_space_id', $space->id)
                ->whereNull('trashed_at')
                ->whereDate('taken_at', '>=', $trip->start_date)
                ->whereDate('taken_at', '<=', $trip->end_date)
                ->whereNotIn('id', $alreadyLinked)
                ->orderBy('taken_at')
                ->get();

            $count = $suggested->count();

            return response()->json([
                'count'   => $count,
                'samples' => $suggested->take(6)->map(fn($p) => [
                    'id'            => $p->id,
                    'uuid'          => $p->uuid,
                    'thumbnail_url' => $p->thumbnail_url,
                    'taken_at'      => $p->taken_at,
                ])->values(),
                'all_ids' => $suggested->pluck('id')->values(),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('TripController::suggestMedia failed: ' . $e->getMessage());
            return response()->json(['count' => 0, 'samples' => [], 'all_ids' => []]);
        }
    }

    /**
     * POST /api/v1/trips/{id}/media
     */
    public function addMedia(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (! $this->tripBelongsToSpace($id, $space->id)) {
            return response()->json(['error' => 'not found'], 404);
        }
        $v = $request->validate([
            'media_ids'   => 'required|array|max:5000',
            'media_ids.*' => 'integer',
        ]);

        $validIds = MediaItem::where('gallery_space_id', $space->id)
            ->whereIn('id', $v['media_ids'])
            ->pluck('id');

        $now = now();
        foreach ($validIds as $mediaId) {
            DB::table('trip_media')->insertOrIgnore([
                'trip_id'       => $id,
                'media_item_id' => $mediaId,
                'added_at'      => $now,
            ]);
        }

        return response()->json(['added' => $validIds->count()]);
    }

    /**
     * DELETE /api/v1/trips/{id}/media/{mediaId}
     */
    public function removeMedia(Request $request, int $id, int $mediaId): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (! $this->tripBelongsToSpace($id, $space->id)) {
            return response()->json(['error' => 'not found'], 404);
        }

        DB::table('trip_media')
            ->where('trip_id', $id)
            ->where('media_item_id', $mediaId)
            ->delete();

        return response()->json(['status' => 'removed']);
    }

    /** Turn linked trip photos into one shared memory, without leaving the trip workspace. */
    public function createSharedMemory(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $space = $user->gallerySpaces()->firstOrFail();
        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->firstOrFail();
        $data = $request->validate([
            'title' => 'nullable|string|max:160',
            'note' => 'nullable|string|max:5000',
            'media_item_ids' => 'required|array|min:1|max:30',
            'media_item_ids.*' => 'integer|distinct',
        ]);

        $mediaIds = $data['media_item_ids'];
        $linkedMediaCount = DB::table('trip_media')
            ->join('media_items', 'media_items.id', '=', 'trip_media.media_item_id')
            ->where('trip_media.trip_id', $trip->id)
            ->where('media_items.gallery_space_id', $space->id)
            ->whereNull('media_items.trashed_at')
            ->whereIn('media_items.id', $mediaIds)
            ->count();
        abort_unless($linkedMediaCount === count($mediaIds), 422, 'Pro vzpomínku lze vybrat jen média přiřazená k této cestě.');

        [$memory, $created] = $this->upsertTripMemory($trip, $space->id, $user->id, $data['title'] ?? $trip->name, $data['note'] ?? null, $mediaIds);

        return response()->json($memory, $created ? 201 : 200);
    }

    /** Return the one album that closes the trip → gallery → memory loop. */
    public function recapAlbum(Request $request, int $id): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->firstOrFail();
        if (! Schema::hasColumn('albums', 'trip_id')) {
            return response()->json(['message' => 'Pro cestovní album dokončete migrace aplikace.'], 503);
        }

        $album = Album::query()->where('trip_id', $trip->id)->where('gallery_space_id', $space->id)->first();
        return response()->json(['album' => $album ? $this->recapAlbumPayload($album) : null]);
    }

    /**
     * Build or synchronize an editable story album from deliberately selected
     * trip media. Existing hand-edited story blocks are never overwritten.
     */
    public function createRecapAlbum(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $space = $user->gallerySpaces()->firstOrFail();
        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->firstOrFail();
        if (! Schema::hasColumn('albums', 'trip_id')) {
            return response()->json(['message' => 'Pro cestovní album dokončete migrace aplikace.'], 503);
        }
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:5000',
            'media_item_ids' => 'required|array|min:1|max:200',
            'media_item_ids.*' => 'required|integer|distinct',
            'cover_media_id' => 'nullable|integer',
        ]);

        $requestedIds = collect($data['media_item_ids'])->map(fn ($mediaId) => (int) $mediaId)->values();
        $mediaById = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->whereIn('id', $requestedIds)
            ->get()
            ->keyBy('id');
        $linkedIds = DB::table('trip_media')->where('trip_id', $trip->id)->whereIn('media_item_id', $requestedIds)->pluck('media_item_id')->map(fn ($mediaId) => (int) $mediaId);
        if ($mediaById->count() !== $requestedIds->count() || $linkedIds->count() !== $requestedIds->count()) {
            abort(422, 'Do cestovního alba lze vložit jen dostupná média přiřazená k této cestě.');
        }
        $orderedMedia = $requestedIds->map(fn ($mediaId) => $mediaById->get($mediaId));
        if (! empty($data['cover_media_id']) && ! $requestedIds->contains((int) $data['cover_media_id'])) {
            abort(422, 'Titulní fotografie musí být součástí vybraných médií.');
        }
        $cover = ! empty($data['cover_media_id'])
            ? $mediaById->get((int) $data['cover_media_id'])
            : $orderedMedia->sortByDesc(fn (MediaItem $media) => ($media->media_type === 'photo' ? 1_000_000_000_000 : 0) + ($media->is_favorite ? 100_000_000_000 : 0) + ((int) $media->rating * 10_000_000_000) + ((int) $media->width * (int) $media->height))->first();

        [$album, $created, $storyCreated, $journalBlocksAdded, $memory] = DB::transaction(function () use ($user, $space, $trip, $data, $orderedMedia, $cover) {
            DB::table('trips')->where('id', $trip->id)->lockForUpdate()->first();
            $album = Album::withTrashed()->where('trip_id', $trip->id)->where('gallery_space_id', $space->id)->first();
            $created = ! $album;
            $title = trim((string) ($data['title'] ?? '')) ?: $trip->name;
            $description = array_key_exists('note', $data) && $data['note'] !== null ? $data['note'] : ($trip->description ?: 'Společné cestovní album.');
            $albumData = [
                'trip_id' => $trip->id,
                'title' => $title,
                'slug' => Str::slug($title),
                'description' => $description,
                'cover_media_id' => $cover->id,
                'event_date_start' => $trip->start_date,
                'event_date_end' => $trip->end_date,
                'story_mode' => true,
                'event_mode' => true,
                'event_start_at' => $trip->start_date . ' 00:00:00',
                'event_end_at' => $trip->end_date . ' 23:59:59',
                'visibility' => 'shared',
                'sort_mode' => 'date_taken',
                'sort_direction' => 'asc',
                'updated_by' => $user->id,
                'sync_status' => 'pending',
            ];
            if ($album) {
                if ($album->trashed()) $album->restore();
                $album->update($albumData);
            } else {
                $album = Album::create($albumData + [
                    'gallery_space_id' => $space->id,
                    'created_by' => $user->id,
                    'icon' => '🧭',
                    'color' => '#c026d3',
                ]);
            }
            $album->rebuildPaths();

            $sync = [];
            foreach ($orderedMedia as $sortOrder => $media) {
                $sync[$media->id] = ['sort_order' => $sortOrder, 'is_cover' => $media->id === $cover->id, 'added_at' => now(), 'added_by' => $user->id];
            }
            $album->media()->sync($sync);
            $album->update(['media_count' => count($sync), 'total_size_bytes' => $orderedMedia->sum('size_bytes')]);

            $permissionRows = DB::table('gallery_space_user')->where('gallery_space_id', $space->id)->pluck('user_id')->map(fn ($userId) => [
                'album_id' => $album->id, 'user_id' => $userId, 'role' => 'editor', 'inherited' => false, 'created_at' => now(), 'updated_at' => now(),
            ])->all();
            if ($permissionRows) DB::table('album_user_permissions')->upsert($permissionRows, ['album_id', 'user_id'], ['role', 'inherited', 'updated_at']);
            CalendarEvent::where('gallery_space_id', $space->id)->where('trip_id', $trip->id)->update(['album_id' => $album->id, 'updated_at' => now()]);

            $storyCreated = DB::table('album_story_blocks')->where('album_id', $album->id)->doesntExist();
            $journalBlocksAdded = $storyCreated
                ? $this->createTripStoryBlocks($album, $trip, $orderedMedia, $user->id)
                : $this->appendMissingJournalStoryBlocks($album, $trip, $user->id);
            [$memory] = $this->upsertTripMemory($trip, $space->id, $user->id, $title, $description, $orderedMedia->take(30)->pluck('id')->all());
            AuditLog::record($created ? 'trip.recap_album.create' : 'trip.recap_album.sync', $album, ['trip_id' => $trip->id, 'media_count' => count($sync), 'story_created' => $storyCreated, 'journal_blocks_added' => $journalBlocksAdded]);

            return [$album->fresh(), $created, $storyCreated, $journalBlocksAdded, $memory];
        });

        $payload = $this->recapAlbumPayload($album) + ['story_created' => $storyCreated, 'journal_blocks_added' => $journalBlocksAdded, 'memory_uuid' => $memory->uuid];
        return response()->json($payload, $created ? 201 : 200);
    }

    /** A shared post-trip note, kept next to the itinerary and its gallery memory. */
    public function reflection(Request $request, int $id): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->firstOrFail();

        if (! Schema::hasTable('trip_reflections')) {
            return response()->json(['message' => 'Pro společné ohlédnutí dokončete migrace aplikace.'], 503);
        }

        $completion = [
            'activities_total' => DB::table('trip_activities')->join('trip_days', 'trip_days.id', '=', 'trip_activities.trip_day_id')->where('trip_days.trip_id', $trip->id)->count(),
            'activities_done' => DB::table('trip_activities')->join('trip_days', 'trip_days.id', '=', 'trip_activities.trip_day_id')->where('trip_days.trip_id', $trip->id)->where('trip_activities.status', 'done')->count(),
            'media_count' => DB::table('trip_media')->where('trip_id', $trip->id)->count(),
            'has_shared_memory' => DB::table('shared_memory_moments')->where('trip_id', $trip->id)->exists(),
            'has_recap_album' => Schema::hasColumn('albums', 'trip_id') && DB::table('albums')->where('trip_id', $trip->id)->whereNull('deleted_at')->exists(),
            'actual_expenses' => (float) DB::table('trip_expenses')->where('trip_id', $trip->id)->where('state', 'actual')->sum('amount'),
            'currency' => $trip->currency,
        ];

        return response()->json([
            'trip' => ['id' => $trip->id, 'name' => $trip->name, 'status' => $trip->status, 'end_date' => $trip->end_date],
            'reflection' => DB::table('trip_reflections')->where('trip_id', $trip->id)->first(),
            'completion' => $completion,
        ]);
    }

    /** Store one deliberately shared recap, rather than adding another disconnected note. */
    public function upsertReflection(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $space = $user->gallerySpaces()->firstOrFail();
        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->firstOrFail();

        if (! Schema::hasTable('trip_reflections')) {
            return response()->json(['message' => 'Pro společné ohlédnutí dokončete migrace aplikace.'], 503);
        }

        $data = $request->validate([
            'rating' => 'nullable|integer|between:1,5',
            'highlight' => 'nullable|string|max:2000',
            'gratitude' => 'nullable|string|max:2000',
            'next_time' => 'nullable|string|max:2000',
        ]);

        $existing = DB::table('trip_reflections')->where('trip_id', $trip->id)->first();
        $row = ['updated_by' => $user->id, 'updated_at' => now()];
        foreach (['rating', 'highlight', 'gratitude', 'next_time'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field];
            }
        }

        if ($existing) {
            DB::table('trip_reflections')->where('id', $existing->id)->update($row);
            $reflectionId = $existing->id;
        } else {
            $reflectionId = DB::table('trip_reflections')->insertGetId($row + [
                'uuid' => (string) Str::uuid(),
                'trip_id' => $trip->id,
                'gallery_space_id' => $space->id,
                'created_by' => $user->id,
                'created_at' => now(),
            ]);
        }

        return response()->json(DB::table('trip_reflections')->find($reflectionId), $existing ? 200 : 201);
    }

    /** Continue a finished trip as a new shared date, without turning it into a duplicate trip. */
    public function scheduleRevisit(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $space = $user->gallerySpaces()->firstOrFail();
        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->firstOrFail();
        if (! Schema::hasColumn('calendar_events', 'source_trip_id')) {
            return response()->json(['message' => 'Pro plánování návratu dokončete migrace aplikace.'], 503);
        }

        $data = $request->validate([
            'starts_at' => 'required|date|after:now',
            'reminder_minutes' => 'nullable|integer|min:0|max:525600',
            'title' => 'nullable|string|max:160',
        ]);
        $startsAt = Carbon::parse($data['starts_at']);
        $existing = CalendarEvent::where('gallery_space_id', $space->id)->where('source_trip_id', $trip->id)->where('starts_at', $startsAt)->first();
        if ($existing) {
            return response()->json($existing->load('participants:id,name,email', 'reminders'));
        }

        $reflection = Schema::hasTable('trip_reflections') ? DB::table('trip_reflections')->where('trip_id', $trip->id)->first() : null;
        $placeName = Schema::hasTable('trip_waypoints')
            ? DB::table('trip_waypoints')->where('trip_id', $trip->id)->orderBy('sort_order')->value('place_name')
            : null;
        $event = CalendarEvent::create([
            'gallery_space_id' => $space->id,
            'created_by' => $user->id,
            'source_trip_id' => $trip->id,
            'title' => $data['title'] ?? "Návrat: {$trip->name}",
            'description' => $reflection?->highlight ? "Navazuje na společný zážitek: {$reflection->highlight}" : "Navazuje na vaši cestu „{$trip->name}“.",
            'type' => 'outing',
            'status' => 'planned',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'timezone' => $trip->timezone ?: 'Europe/Prague',
            'place_name' => $placeName,
            'is_private' => false,
            'metadata' => ['kind' => 'trip_revisit'],
        ]);
        $memberIds = DB::table('gallery_space_user')->where('gallery_space_id', $space->id)->pluck('user_id');
        foreach ($memberIds as $memberId) {
            $event->participants()->syncWithoutDetaching([(int) $memberId => [
                'role' => (int) $memberId === $user->id ? 'owner' : 'guest',
                'response' => (int) $memberId === $user->id ? 'accepted' : 'pending',
            ]]);
            $event->reminders()->create([
                'user_id' => $memberId,
                'channel' => 'database',
                'remind_at' => $startsAt->copy()->subMinutes((int) ($data['reminder_minutes'] ?? 10080)),
                'status' => 'pending',
            ]);
        }

        return response()->json($event->load('participants:id,name,email', 'reminders'), 201);
    }

    // ─── Waypoints ─────────────────────────────────────────────────────────

    /**
     * POST /api/v1/trips/{id}/waypoints
     */
    public function addWaypoint(Request $request, int $id): JsonResponse
    {
        // Guard: if table doesn't exist yet (migration pending), return clear error
        if (! Schema::hasTable('trip_waypoints')) {
            return response()->json([
                'error'   => 'trips_not_ready',
                'message' => 'Spusťte php artisan migrate na serveru.',
            ], 503);
        }

        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (! $this->tripBelongsToSpace($id, $space->id)) {
            return response()->json(['error' => 'not found'], 404);
        }

        $rules = [
            'place_name'        => 'required|string|max:255',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
            'notes'             => 'nullable|string|max:2000',
            'arrived_at'        => 'nullable|date',
            'departed_at'       => 'nullable|date',
            'transport_mode'    => 'nullable|in:car,train,bus,plane,walk,bike,boat',
            'duration_override' => 'nullable|integer|min:0|max:10000',
        ];

        $isBulk = $request->has('waypoints');
        if ($isBulk) {
            $validated = $request->validate(array_merge([
                'waypoints' => 'required|array|min:1|max:50',
            ], collect($rules)->mapWithKeys(fn ($rule, $key) => ["waypoints.*.{$key}" => $rule])->all()));
            $items = $validated['waypoints'];
        } else {
            $items = [$request->validate($rules)];
        }

        $hasTransportColumns = Schema::hasColumn('trip_waypoints', 'transport_mode');
        $created = DB::transaction(function () use ($id, $items, $hasTransportColumns) {
            $maxOrder = DB::table('trip_waypoints')
                ->where('trip_id', $id)
                ->lockForUpdate()
                ->max('sort_order') ?? -1;
            $created = [];

            foreach ($items as $offset => $item) {
                $insertData = [
                    'trip_id'     => $id,
                    'place_name'  => $item['place_name'],
                    'latitude'    => $item['latitude'] ?? null,
                    'longitude'   => $item['longitude'] ?? null,
                    'notes'       => $item['notes'] ?? null,
                    'arrived_at'  => $item['arrived_at'] ?? null,
                    'departed_at' => $item['departed_at'] ?? null,
                    'sort_order'  => $maxOrder + $offset + 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
                if ($hasTransportColumns) {
                    $insertData['transport_mode']    = $item['transport_mode'] ?? null;
                    $insertData['duration_override'] = $item['duration_override'] ?? null;
                }

                $wpId = DB::table('trip_waypoints')->insertGetId($insertData);
                $created[] = $this->castWaypoint(DB::table('trip_waypoints')->find($wpId));
            }

            return $created;
        });

        return response()->json($isBulk ? $created : $created[0], 201);
    }

    /**
     * PATCH /api/v1/trips/{id}/waypoints/{wpId}
     * Update transport_mode, notes, or dates on a single waypoint.
     */
    public function updateWaypoint(Request $request, int $id, int $wpId): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (! $this->tripBelongsToSpace($id, $space->id)) {
            return response()->json(['error' => 'not found'], 404);
        }
        if (! DB::table('trip_waypoints')->where('id', $wpId)->where('trip_id', $id)->exists()) {
            return response()->json(['error' => 'not found'], 404);
        }

        $v = $request->validate([
            'transport_mode'   => 'nullable|in:car,train,bus,plane,walk,bike,boat',
            'duration_override' => 'nullable|integer|min:0|max:10000',
            'notes'            => 'nullable|string|max:2000',
            'arrived_at'       => 'nullable|date',
            'departed_at'      => 'nullable|date',
        ]);

        // Only update new columns if migration has run
        if (! \Illuminate\Support\Facades\Schema::hasColumn('trip_waypoints', 'transport_mode')) {
            unset($v['transport_mode'], $v['duration_override']);
        }

        DB::table('trip_waypoints')
            ->where('id', $wpId)
            ->where('trip_id', $id)
            ->update(array_merge($v, ['updated_at' => now()]));


        $wp = DB::table('trip_waypoints')->where('id', $wpId)->where('trip_id', $id)->first();
        if ($wp) {
            $wp->latitude  = $wp->latitude  !== null ? (float) $wp->latitude  : null;
            $wp->longitude = $wp->longitude !== null ? (float) $wp->longitude : null;
        }
        return response()->json($wp);
    }

    /**
     * PUT /api/v1/trips/{id}/waypoints/reorder
     */
    public function reorderWaypoints(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (! $this->tripBelongsToSpace($id, $space->id)) {
            return response()->json(['error' => 'not found'], 404);
        }

        $v = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        $currentIds = DB::table('trip_waypoints')->where('trip_id', $id)->pluck('id')->map(fn ($value) => (int) $value)->all();
        $requestedIds = array_map('intval', $v['order']);
        sort($currentIds);
        $sortedRequestedIds = $requestedIds;
        sort($sortedRequestedIds);
        if (count($requestedIds) !== count(array_unique($requestedIds)) || $currentIds !== $sortedRequestedIds) {
            return response()->json(['message' => 'Pořadí musí obsahovat všechny zastávky právě jednou.'], 422);
        }

        DB::transaction(function () use ($id, $requestedIds) {
            foreach ($requestedIds as $i => $wpId) {
                DB::table('trip_waypoints')
                    ->where('id', $wpId)
                    ->where('trip_id', $id)
                    ->update(['sort_order' => $i, 'updated_at' => now()]);
            }
        });

        return response()->json(['reordered' => count($v['order'])]);
    }

    /**
     * GET /api/v1/trips/route-distance
     * Proxy OSRM open routing for real road/walk/cycle distances.
     * Cached 7 days. No API key needed (uses public OSRM instance).
     */
    public function routeDistance(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from_lat' => 'required|numeric|between:-90,90',
            'from_lng' => 'required|numeric|between:-180,180',
            'to_lat'   => 'required|numeric|between:-90,90',
            'to_lng'   => 'required|numeric|between:-180,180',
            'mode'     => 'nullable|in:driving,walking,cycling',
        ]);

        $mode = $v['mode'] ?? 'driving';
        $cacheKey = sprintf(
            'osrm:%s:%.4f,%.4f:%.4f,%.4f',
            $mode,
            $v['from_lat'],
            $v['from_lng'],
            $v['to_lat'],
            $v['to_lng']
        );

        $result = Cache::remember($cacheKey, 86400 * 7, function () use ($v, $mode) {
            $url = sprintf(
                'http://router.project-osrm.org/route/v1/%s/%.6f,%.6f;%.6f,%.6f?overview=false',
                $mode,
                (float) $v['from_lng'],
                (float) $v['from_lat'],
                (float) $v['to_lng'],
                (float) $v['to_lat']
            );

            if (! function_exists('curl_init')) {
                return null;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_USERAGENT      => 'MakiGallery/1.0 (gallery.stanektech.cz)',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($errno || ! $resp) {
                return null;
            }

            $data = json_decode($resp, true);
            if (! isset($data['routes'][0])) {
                return null;
            }

            return [
                'distance_km'  => round($data['routes'][0]['distance'] / 1000, 1),
                'duration_min' => (int) round($data['routes'][0]['duration'] / 60),
                'source'       => 'osrm',
            ];
        });

        if (! $result) {
            return response()->json(['error' => 'routing_unavailable'], 503);
        }

        return response()->json($result);
    }

    /**
     * GET /api/v1/trips/transport-prices?from={}&to={}&date=YYYY-MM-DD
     * Real-time pricing from RegioJet + FlixBus (their public/semi-public APIs).
     * Falls back to empty array — UI uses estimated prices as fallback.
     */
    public function transportPrices(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => 'required|string|max:120',
            'to'   => 'required|string|max:120',
            'date' => 'required|date_format:Y-m-d',
            'from_lat' => 'nullable|numeric|between:-90,90', 'from_lng' => 'nullable|numeric|between:-180,180',
            'to_lat' => 'nullable|numeric|between:-90,90', 'to_lng' => 'nullable|numeric|between:-180,180',
        ]);

        $results = $this->transportSearch->search($v + ['adults' => 1, 'mode' => 'all']);
        $prices = collect($results)->whereNotNull('price')->map(fn (array $item) => [
            'carrier' => $item['carrier'], 'icon' => $item['icon'], 'min_price' => $item['price_per_pax'] ?? $item['price'],
            'currency' => $item['currency'], 'source' => $item['source'], 'note' => $item['note'] ?? null,
            'book_url' => $item['book_url'], 'mode' => $item['mode'] ?? null,
        ])->values();

        return response()->json($prices);
    }

    // ─── RegioJet ──────────────────────────────────────────────────────────

    private function regiojetPrices(string $from, string $to, string $date): array
    {
        $cities = Cache::remember('rj_cities_v2', 86400, function () {
            $resp = $this->curlFetch('https://brn-ybus-pubapi.sa.cz/restapi/consts/locations?locale=cs', [
                'X-Currency: CZK',
                'Accept: application/json',
            ]);
            $data = json_decode($resp, true);
            $list = [];
            foreach (($data['cities'] ?? []) as $c) {
                if (isset($c['id'], $c['name'])) {
                    $list[] = ['id' => (int) $c['id'], 'name' => (string) $c['name']];
                }
            }
            return $list;
        });

        $fromId = $this->fuzzyFindCityId($cities, $from);
        $toId   = $this->fuzzyFindCityId($cities, $to);

        if (! $fromId || ! $toId || $fromId === $toId) {
            return [];
        }

        $url  = 'https://brn-ybus-pubapi.sa.cz/restapi/routes/search/simple?' . http_build_query([
            'tariffs'          => 'REGULAR',
            'toLocationType'   => 'CITY',
            'toLocationId'     => $toId,
            'fromLocationType' => 'CITY',
            'fromLocationId'   => $fromId,
            'departureDate'    => $date,
            'locale'           => 'cs',
        ]);
        $resp = $this->curlFetch($url, ['X-Currency: CZK', 'Accept: application/json']);
        $data = json_decode($resp, true);

        if (empty($data['routes'])) {
            return [];
        }

        // Group by vehicle type, collect minimum price
        $best = [];
        foreach ($data['routes'] as $route) {
            $price = $route['priceFrom'] ?? null;
            if ($price === null) {
                continue;
            }
            $type = in_array('TRAIN', $route['vehicleTypes'] ?? []) ? 'train' : 'bus';
            if (! isset($best[$type]) || $price < $best[$type]['price']) {
                $best[$type] = [
                    'price'    => (float) $price,
                    'currency' => 'CZK',
                    'dep'      => $route['departureTime'] ?? null,
                    'arr'      => $route['arrivalTime']   ?? null,
                ];
            }
        }

        $f  = urlencode($from);
        $t  = urlencode($to);
        $result = [];

        if (isset($best['bus'])) {
            $result[] = [
                'carrier'   => 'RegioJet Bus',
                'icon'      => '🟡',
                'min_price' => (int) ceil($best['bus']['price']),
                'currency'  => 'CZK',
                'source'    => 'live',
                'note'      => 'základní tarif',
                'book_url'  => 'https://regiojet.cz/',
            ];
        }
        if (isset($best['train'])) {
            $result[] = [
                'carrier'   => 'RegioJet vlak',
                'icon'      => '🟡',
                'min_price' => (int) ceil($best['train']['price']),
                'currency'  => 'CZK',
                'source'    => 'live',
                'note'      => 'základní tarif',
                'book_url'  => 'https://regiojet.cz/',
            ];
        }

        return $result;
    }

    // ─── FlixBus ───────────────────────────────────────────────────────────

    private function flixbusPrices(string $from, string $to, string $date): array
    {
        $fromCity = $this->flixbusCity($from);
        $toCity   = $this->flixbusCity($to);

        if (! $fromCity || ! $toCity) {
            return [];
        }

        $url  = 'https://global.api.flixbus.com/search/service/v4/search?' . http_build_query([
            'from_city_id'   => $fromCity['id'],
            'to_city_id'     => $toCity['id'],
            'departure_date' => $date,
            'number_adult'   => 1,
            'currency'       => 'CZK',
            'locale'         => 'cs',
        ]);
        $resp = $this->curlFetch($url, ['Accept: application/json']);
        $data = json_decode($resp, true);

        // FlixBus wraps trips under different keys depending on API version
        $trips = $data['trips'] ?? $data['available']['trips'] ?? [];

        $minPrice = null;
        foreach ($trips as $trip) {
            $amount = $trip['available']['lowest_price']['amount']
                ?? $trip['min_price']['amount']
                ?? null;
            if ($amount !== null && ($minPrice === null || $amount < $minPrice)) {
                $minPrice = (float) $amount;
            }
        }

        if ($minPrice === null) {
            return [];
        }

        return [[
            'carrier'   => 'FlixBus',
            'icon'      => '🟢',
            'min_price' => (int) ceil($minPrice),
            'currency'  => 'CZK',
            'source'    => 'live',
            'note'      => 'od nejnižší ceny',
            'book_url'  => 'https://www.flixbus.cz/',
        ]];
    }

    private function flixbusCity(string $name): ?array
    {
        $key = 'fb_city:' . md5(mb_strtolower($name));
        return Cache::remember($key, 86400 * 7, function () use ($name) {
            $url  = 'https://global.api.flixbus.com/search/service/v4/cities/autocomplete?' . http_build_query([
                'q'    => $name,
                'lang' => 'cs',
            ]);
            $resp = $this->curlFetch($url, ['Accept: application/json']);
            $data = json_decode($resp, true);
            if (empty($data) || ! isset($data[0]['id'])) {
                return null;
            }
            return ['id' => $data[0]['id'], 'name' => $data[0]['name'] ?? $name];
        });
    }

    // ─── Shared helpers ────────────────────────────────────────────────────

    /**
     * Fuzzy-find a city ID by name (handles diacritics, suffixes like "hlavní nádraží").
     */
    private function fuzzyFindCityId(array $cities, string $name): ?int
    {
        $clean = fn(string $s) => mb_strtolower(
            preg_replace('/\s+(hlavní|hl\.|nádraží|bus|vlak|letiště|airport|centrum|město)\b.*/iu', '', trim($s)) ?? '',
            'UTF-8'
        );

        $needle = $clean($name);

        // 1. Exact match after cleaning
        foreach ($cities as $c) {
            if ($clean($c['name']) === $needle) {
                return $c['id'];
            }
        }

        // 2. Starts-with match
        foreach ($cities as $c) {
            $hay = $clean($c['name']);
            if (str_starts_with($hay, $needle) || str_starts_with($needle, $hay)) {
                return $c['id'];
            }
        }

        return null;
    }

    private function curlFetch(string $url, array $headers = []): string
    {
        if (! function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'MakiGallery/1.0 (gallery.stanektech.cz)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno || ! $resp || $status >= 400) {
            throw new \RuntimeException("Dopravní portál vrátil HTTP {$status}");
        }
        return $resp;
    }

    /**
     * DELETE /api/v1/trips/{id}/waypoints/{wpId}
     */
    public function removeWaypoint(Request $request, int $id, int $wpId): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (! $this->tripBelongsToSpace($id, $space->id)) {
            return response()->json(['error' => 'not found'], 404);
        }

        DB::table('trip_waypoints')->where('id', $wpId)->where('trip_id', $id)->delete();

        return response()->json(['status' => 'removed']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function upsertTripMemory(object $trip, int $spaceId, int $userId, string $title, ?string $note, array $mediaIds): array
    {
        $existing = DB::table('shared_memory_moments')->where('trip_id', $trip->id)->first();
        $eventIds = DB::table('calendar_events')->where('trip_id', $trip->id)->where('gallery_space_id', $spaceId)->orderBy('starts_at')->pluck('id');
        if (! $existing && $eventIds->isNotEmpty()) {
            $existing = DB::table('shared_memory_moments')->whereIn('calendar_event_id', $eventIds)->first();
        }
        $calendarEventId = $existing?->calendar_event_id;
        if (! $calendarEventId) {
            $usedEventIds = DB::table('shared_memory_moments')->whereIn('calendar_event_id', $eventIds)->pluck('calendar_event_id');
            $calendarEventId = $eventIds->diff($usedEventIds)->first();
        }

        $row = [
            'trip_id' => $trip->id,
            'calendar_event_id' => $calendarEventId ?: null,
            'gallery_space_id' => $spaceId,
            'created_by' => $existing?->created_by ?? $userId,
            'title' => $title,
            'note' => $note ?? $existing?->note ?? $trip->description,
            'happened_on' => $trip->end_date,
            'media_item_ids' => json_encode(array_values($mediaIds)),
            'is_favorite' => true,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('shared_memory_moments')->where('id', $existing->id)->update($row);
            $id = $existing->id;
        } else {
            $id = DB::table('shared_memory_moments')->insertGetId($row + ['uuid' => (string) Str::uuid(), 'created_at' => now()]);
        }

        return [DB::table('shared_memory_moments')->find($id), ! $existing];
    }

    private function createTripStoryBlocks(Album $album, object $trip, $media, int $userId): int
    {
        $blocks = [];
        $journalBlocks = 0;
        $add = function (string $type, array $content) use (&$blocks, $album, $userId) {
            $blocks[] = [
                'album_id' => $album->id,
                'created_by' => $userId,
                'type' => $type,
                'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => count($blocks),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        $add('heading', ['text' => $album->title, 'level' => 1]);
        $dateLabel = Carbon::parse($trip->start_date)->format('d. m. Y') . ' – ' . Carbon::parse($trip->end_date)->format('d. m. Y');
        $add('text', ['body' => $dateLabel . ($trip->description ? "\n\n" . $trip->description : '')]);

        $reflection = Schema::hasTable('trip_reflections') ? DB::table('trip_reflections')->where('trip_id', $trip->id)->first() : null;
        if ($reflection?->highlight) $add('quote', ['quote' => $reflection->highlight, 'author' => 'Náš nejhezčí moment']);
        $reflectionNotes = array_values(array_filter([
            $reflection?->gratitude ? 'Za co jsme rádi: ' . $reflection->gratitude : null,
            $reflection?->next_time ? 'Příště: ' . $reflection->next_time : null,
        ]));
        if ($reflectionNotes) $add('text', ['body' => implode("\n\n", $reflectionNotes)]);

        $firstWaypoint = DB::table('trip_waypoints')->where('trip_id', $trip->id)->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('sort_order')->first();
        if ($firstWaypoint) $add('map', ['latitude' => (float) $firstWaypoint->latitude, 'longitude' => (float) $firstWaypoint->longitude, 'zoom' => 11, 'label' => $firstWaypoint->place_name]);

        $days = DB::table('trip_days')->where('trip_id', $trip->id)->orderBy('sort_order')->orderBy('date')->get();
        $journal = $this->storyJournalEntries((int) $trip->id);
        $usedMediaIds = collect();
        $usedJournalIds = collect();
        foreach ($days as $index => $day) {
            $activities = DB::table('trip_activities')->where('trip_day_id', $day->id)->orderBy('sort_order')->get();
            $dayMedia = $media->filter(fn (MediaItem $item) => $item->taken_at?->toDateString() === $day->date);
            if ($activities->isEmpty() && $dayMedia->isEmpty() && ! $day->notes) continue;
            $add('heading', ['text' => ($day->title ?: 'Den ' . ($index + 1)) . ' · ' . Carbon::parse($day->date)->format('d. m. Y'), 'level' => 2]);
            $activityLines = $activities->map(fn ($activity) => '• ' . ($activity->starts_at ? substr((string) $activity->starts_at, 0, 5) . ' ' : '') . $activity->title)->all();
            $dayText = array_values(array_filter([$day->notes, $activityLines ? implode("\n", $activityLines) : null]));
            if ($dayText) $add('text', ['body' => implode("\n\n", $dayText)]);
            foreach ($journal->where('trip_day_id', $day->id) as $entry) {
                $this->addJournalStoryBlock($add, $entry);
                $journalBlocks++;
                $usedJournalIds->push($entry->id);
            }
            $photos = $dayMedia->where('media_type', 'photo')->take(9);
            if ($photos->isNotEmpty()) {
                $add('photo', ['media_uuids' => $photos->pluck('uuid')->all(), 'layout' => $photos->count() === 1 ? 'single' : ($photos->count() <= 4 ? 'grid2' : 'grid3'), 'caption' => $day->title]);
                $usedMediaIds = $usedMediaIds->merge($photos->pluck('id'));
            }
        }

        $remainingPhotos = $media->where('media_type', 'photo')->reject(fn (MediaItem $item) => $usedMediaIds->contains($item->id))->take(9);
        if ($remainingPhotos->isNotEmpty()) $add('photo', ['media_uuids' => $remainingPhotos->pluck('uuid')->all(), 'layout' => $remainingPhotos->count() === 1 ? 'single' : 'grid3', 'caption' => 'Další společné momenty']);
        $video = $media->firstWhere('media_type', 'video');
        if ($video) $add('video', ['media_uuid' => $video->uuid, 'caption' => 'Video z cesty']);

        $remainingJournal = $journal->reject(fn ($entry) => $usedJournalIds->contains($entry->id));
        if ($remainingJournal->isNotEmpty()) {
            $add('heading', ['text' => 'Naše zápisky z cesty', 'level' => 2]);
            foreach ($remainingJournal as $entry) {
                $this->addJournalStoryBlock($add, $entry);
                $journalBlocks++;
            }
        }

        if ($blocks) DB::table('album_story_blocks')->insert($blocks);
        return $journalBlocks;
    }

    private function appendMissingJournalStoryBlocks(Album $album, object $trip, int $userId): int
    {
        $existingIds = DB::table('album_story_blocks')->where('album_id', $album->id)->pluck('content')
            ->map(fn ($content) => json_decode($content ?: '{}', true) ?: [])
            ->pluck('source_journal_entry_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id);
        $missing = $this->storyJournalEntries((int) $trip->id)->reject(fn ($entry) => $existingIds->contains((int) $entry->id));
        if ($missing->isEmpty()) return 0;

        $sortOrder = ((int) DB::table('album_story_blocks')->where('album_id', $album->id)->max('sort_order')) + 1;
        $rows = [];
        $add = function (string $type, array $content) use (&$rows, &$sortOrder, $album, $userId) {
            $rows[] = ['album_id' => $album->id, 'created_by' => $userId, 'type' => $type, 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'sort_order' => $sortOrder++, 'created_at' => now(), 'updated_at' => now()];
        };
        $add('heading', ['text' => 'Nové zápisky z cesty', 'level' => 2, 'source' => 'travel_journal']);
        foreach ($missing as $entry) $this->addJournalStoryBlock($add, $entry);
        DB::table('album_story_blocks')->insert($rows);
        return $missing->count();
    }

    private function storyJournalEntries(int $tripId)
    {
        if (! Schema::hasColumn('travel_journal_entries', 'visibility')) return collect();
        $query = DB::table('travel_journal_entries as entry')
            ->join('users', 'users.id', '=', 'entry.user_id')
            ->where('entry.trip_id', $tripId)
            ->where('entry.visibility', 'shared')
            ->where('entry.is_story_worthy', true)
            ->whereIn('entry.type', ['note', 'voice', 'location'])
            ->orderBy('entry.recorded_at');
        if (Schema::hasTable('travel_journal_recordings')) {
            return $query->leftJoin('travel_journal_recordings as recording', 'recording.journal_entry_id', '=', 'entry.id')
                ->get(['entry.*', 'users.name as user_name', 'recording.uuid as recording_uuid', 'recording.duration_ms as recording_duration_ms', 'recording.mime_type as recording_mime_type']);
        }
        return $query->get(['entry.*', 'users.name as user_name']);
    }

    private function addJournalStoryBlock(callable $add, object $entry): void
    {
        $source = ['source' => 'travel_journal', 'source_journal_entry_id' => (int) $entry->id, 'recorded_at' => $entry->recorded_at, 'mood' => $entry->mood];
        if ($entry->type === 'location' && $entry->latitude !== null && $entry->longitude !== null) {
            $add('map', $source + ['latitude' => (float) $entry->latitude, 'longitude' => (float) $entry->longitude, 'zoom' => 14, 'label' => $entry->content ?: 'Místo z cestovního deníku']);
            return;
        }
        $content = $source + ['quote' => $entry->content, 'author' => $entry->user_name];
        if ($entry->type === 'voice' && ! empty($entry->recording_uuid)) {
            $content += ['audio_url' => "/api/v1/trips/{$entry->trip_id}/journal/{$entry->id}/recording", 'audio_duration_ms' => (int) ($entry->recording_duration_ms ?? 0), 'audio_mime_type' => $entry->recording_mime_type ?? null];
        }
        $add('quote', $content);
    }

    private function recapAlbumPayload(Album $album): array
    {
        $memory = DB::table('shared_memory_moments')->where('trip_id', $album->trip_id)->first(['uuid', 'title']);
        return [
            'id' => $album->id,
            'uuid' => $album->uuid,
            'trip_id' => $album->trip_id,
            'title' => $album->title,
            'media_count' => (int) $album->media_count,
            'cover_media_id' => $album->cover_media_id,
            'story_blocks_count' => DB::table('album_story_blocks')->where('album_id', $album->id)->count(),
            'memory' => $memory ? ['uuid' => $memory->uuid, 'title' => $memory->title] : null,
            'curation' => $this->albumCuration->health($album),
            'updated_at' => $album->updated_at?->toIso8601String(),
        ];
    }

    private function tripBelongsToSpace(int $tripId, int $spaceId): bool
    {
        return DB::table('trips')->where('id', $tripId)->where('gallery_space_id', $spaceId)->exists();
    }

    private function enrichTrip(object $trip): object
    {
        try {
            $waypoints = DB::table('trip_waypoints')
                ->where('trip_id', $trip->id)
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($wp) => $this->castWaypoint($wp));
            $mediaCount = DB::table('trip_media')
                ->join('media_items', 'media_items.id', '=', 'trip_media.media_item_id')
                ->where('trip_media.trip_id', $trip->id)
                ->whereNull('media_items.trashed_at')
                ->count();
            $coverThumb = null;
            if (! empty($trip->cover_media_id)) {
                $cover = MediaItem::with('variants')->find($trip->cover_media_id);
                $coverThumb = $cover?->thumbnail_url;
            }
            if (! $coverThumb && $mediaCount > 0) {
                $firstId = DB::table('trip_media')
                    ->join('media_items', 'media_items.id', '=', 'trip_media.media_item_id')
                    ->where('trip_media.trip_id', $trip->id)
                    ->whereNull('media_items.trashed_at')
                    ->orderBy('media_items.taken_at')
                    ->value('media_items.id');

                if ($firstId) {
                    $item = MediaItem::with('variants')->find($firstId);
                    $coverThumb = $item?->thumbnail_url;
                }
            }

            $trip->waypoints     = $waypoints;
            $trip->media_count   = $mediaCount;
            $trip->cover_thumb   = $coverThumb;
            $trip->duration_days = (int) Carbon::parse($trip->start_date)->diffInDays($trip->end_date) + 1;

            return $trip;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('TripController::enrichTrip failed: ' . $e->getMessage());
            $trip->waypoints     = collect();
            $trip->media_count   = 0;
            $trip->cover_thumb   = null;
            $trip->duration_days = 1;
            return $trip;
        }
    }

    private function castWaypoint(?object $waypoint): ?object
    {
        if ($waypoint) {
            $waypoint->latitude  = $waypoint->latitude !== null ? (float) $waypoint->latitude : null;
            $waypoint->longitude = $waypoint->longitude !== null ? (float) $waypoint->longitude : null;
        }

        return $waypoint;
    }
}
