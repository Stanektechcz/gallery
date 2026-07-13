<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\CalendarEvent;
use App\Models\MediaItem;
use App\Models\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlaceController extends Controller
{
    /**
     * GET /api/v1/places
     * List all places for the current gallery space, enriched with visit stats.
     */
    public function index(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();

        $places = Place::where('gallery_space_id', $space->id)
            ->orderBy('name')
            ->get();

        return response()->json($places->map(fn($p) => $this->withStats($p, $space->id)));
    }

    /**
     * POST /api/v1/places
     */
    public function store(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();

        $v = $request->validate([
            'name'          => 'required|string|max:200',
            'type'          => 'nullable|in:country,city,business,restaurant,museum,hotel,home,custom',
            'country'       => 'nullable|string|max:100',
            'country_code'  => 'nullable|string|max:3',
            'city'          => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:255',
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:50000',
            'description'   => 'nullable|string|max:5000',
            'website_url'   => 'nullable|url|max:512',
            'osm_id'        => 'nullable|string|max:50',
            'osm_type'      => 'nullable|string|max:20',
            'is_rain_friendly' => 'nullable|boolean', 'is_accessible' => 'nullable|boolean', 'is_photogenic' => 'nullable|boolean', 'opens_early' => 'nullable|boolean',
            'price_level' => 'nullable|integer|between:1,4', 'estimated_visit_minutes' => 'nullable|integer|between:5,1440', 'personal_rating' => 'nullable|integer|between:1,5', 'next_time_note' => 'nullable|string|max:5000',
        ]);

        $place = Place::create(array_merge($v, [
            'gallery_space_id' => $space->id,
            'source'           => 'manual',
            'created_by'       => $request->user()->id,
            'radius_meters'    => $v['radius_meters'] ?? 500,
            'type'             => $v['type'] ?? 'custom',
        ]));

        return response()->json($this->withStats($place, $space->id), 201);
    }

    /**
     * GET /api/v1/places/{place}
     */
    public function show(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $this->authorizePlace($place, $space->id);

        return response()->json($this->withStats($place, $space->id));
    }

    /**
     * PATCH /api/v1/places/{place}
     */
    public function update(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $this->authorizePlace($place, $space->id);

        $v = $request->validate([
            'name'          => 'nullable|string|max:200',
            'type'          => 'nullable|in:country,city,business,restaurant,museum,hotel,home,custom',
            'country'       => 'nullable|string|max:100',
            'city'          => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:255',
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:50000',
            'description'   => 'nullable|string|max:5000',
            'website_url'   => 'nullable|url|max:512',
            'is_rain_friendly' => 'nullable|boolean', 'is_accessible' => 'nullable|boolean', 'is_photogenic' => 'nullable|boolean', 'opens_early' => 'nullable|boolean',
            'price_level' => 'nullable|integer|between:1,4', 'estimated_visit_minutes' => 'nullable|integer|between:5,1440', 'personal_rating' => 'nullable|integer|between:1,5', 'next_time_note' => 'nullable|string|max:5000',
        ]);

        $place->update(array_filter($v, fn($val) => $val !== null));

        return response()->json($this->withStats($place->fresh(), $space->id));
    }

    /**
     * DELETE /api/v1/places/{place}
     */
    public function destroy(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $this->authorizePlace($place, $space->id);

        $place->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * GET /api/v1/places/{place}/media
     * All media for this place (GPS proximity + explicit links).
     */
    public function media(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $this->authorizePlace($place, $space->id);

        $items = $this->mediaQuery($place, $space->id)
            ->with('variants')
            ->orderByDesc('taken_at')
            ->limit(500)
            ->get()
            ->map(fn($m) => [
                'id'            => $m->id,
                'uuid'          => $m->uuid,
                'file_name'     => $m->file_name,
                'thumbnail_url' => $m->thumbnail_url,
                'taken_at'      => $m->taken_at,
                'latitude'      => $m->latitude,
                'longitude'     => $m->longitude,
            ]);

        return response()->json($items);
    }

    /**
     * GET /api/v1/places/{place}/albums
     * Albums associated with this place.
     */
    public function albums(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $this->authorizePlace($place, $space->id);

        $albums = Album::where('gallery_space_id', $space->id)
            ->whereHas('places', fn($q) => $q->where('places.id', $place->id))
            ->withCount('mediaItems')
            ->get(['id', 'uuid', 'title', 'cover_path', 'created_at'])
            ->map(fn($a) => [
                'id'          => $a->id,
                'uuid'        => $a->uuid,
                'title'       => $a->title,
                'cover_thumb' => $a->cover_path,
                'media_count' => $a->media_items_count,
            ]);

        return response()->json($albums);
    }

    /**
     * POST /api/v1/places/{place}/auto-link
     * Scan GPS photos within radius and link them to this place.
     */
    public function autoLink(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $this->authorizePlace($place, $space->id);

        if (! $place->latitude || ! $place->longitude) {
            return response()->json(['linked' => 0, 'message' => 'Místo nemá GPS souřadnice']);
        }

        $candidates = $this->mediaQuery($place, $space->id)->pluck('id');

        $already = DB::table('media_place')->where('place_id', $place->id)->pluck('media_item_id');
        $toLink = $candidates->diff($already);

        $now = now();
        foreach ($toLink as $mediaId) {
            DB::table('media_place')->insertOrIgnore([
                'media_item_id' => $mediaId,
                'place_id'      => $place->id,
                'is_primary'    => false,
            ]);
        }

        return response()->json(['linked' => $toLink->count()]);
    }

    /** Add a saved place as a concrete stop in a selected day of a trip. */
    public function addToTripPlan(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $this->authorizePlace($place, $space->id);
        $data = $request->validate([
            'trip_id' => 'required|integer',
            'trip_day_id' => 'required|integer',
            'type' => 'nullable|in:activity,reservation,stay',
            'starts_at' => 'nullable|date_format:H:i',
            'ends_at' => 'nullable|date_format:H:i|after:starts_at',
            'description' => 'nullable|string|max:5000',
        ]);

        $trip = DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $space->id)->first();
        abort_unless($trip, 404);
        $day = DB::table('trip_days')->where('id', $data['trip_day_id'])->where('trip_id', $trip->id)->first();
        abort_unless($day, 422, 'Vybraný den nepatří k této cestě.');

        $activityId = DB::table('trip_activities')->insertGetId([
            'trip_day_id' => $day->id,
            'created_by' => $request->user()->id,
            'type' => $data['type'] ?? 'activity',
            'title' => $place->name,
            'description' => $data['description'] ?? $place->next_time_note ?? $place->description,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'place_name' => $place->name,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
            'status' => 'planned',
            'currency' => $trip->currency ?? 'CZK',
            'metadata' => json_encode(['saved_place_id' => $place->id, 'saved_place_type' => $place->type]),
            'sort_order' => ((int) DB::table('trip_activities')->where('trip_day_id', $day->id)->max('sort_order')) + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(DB::table('trip_activities')->find($activityId), 201);
    }

    /** Keep a saved place connected to a shared wish and its later calendar plan. */
    public function addToWishlist(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail();
        $this->authorizePlace($place, $space->id);
        abort_unless(Schema::hasTable('travel_wishlists') && Schema::hasTable('travel_wishlist_items') && Schema::hasColumn('travel_wishlist_items', 'place_id'), 503, 'Pro přidání místa do přání dokončete migrace aplikace.');
        $data = $request->validate(['wishlist_uuid' => 'required|uuid', 'priority' => 'nullable|integer|between:1,5']);
        $wishlist = DB::table('travel_wishlists')->where('uuid', $data['wishlist_uuid'])->where('gallery_space_id', $space->id)->firstOrFail();
        $existing = DB::table('travel_wishlist_items')->where('wishlist_id', $wishlist->id)->where('place_id', $place->id)->where('status', 'open')->first();
        if ($existing) return response()->json($existing);
        $id = DB::table('travel_wishlist_items')->insertGetId([
            'wishlist_id' => $wishlist->id,
            'place_id' => $place->id,
            'created_by' => $request->user()->id,
            'title' => $place->name,
            'notes' => $place->next_time_note ?? $place->description,
            'category' => 'place',
            'priority' => $data['priority'] ?? max(1, 6 - (int) ($place->personal_rating ?? 3)),
            'estimated_cost' => null,
            'currency' => 'CZK',
            'estimated_minutes' => $place->estimated_visit_minutes,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(DB::table('travel_wishlist_items')->find($id), 201);
    }

    /** Planned visits live on a place, while their date and reservation are mirrored into the shared calendar. */
    public function plans(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail(); $this->authorizePlace($place, $space->id);
        return response()->json(DB::table('place_plans')->where('place_id', $place->id)->orderByDesc('planned_for')->orderByDesc('id')->get());
    }

    public function storePlan(Request $request, Place $place): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail(); $this->authorizePlace($place, $space->id);
        $data = $request->validate(['planned_for' => 'required|date', 'reservation_reference' => 'nullable|string|max:255', 'reservation_url' => 'nullable|url|max:2048', 'notes' => 'nullable|string|max:5000']);
        if (!empty($data['reservation_url']) && !Str::startsWith($data['reservation_url'], 'https://')) abort(422, 'Odkaz na rezervaci musí používat HTTPS.');
        $event = CalendarEvent::create(['gallery_space_id' => $space->id, 'created_by' => $request->user()->id, 'title' => $place->name, 'description' => $data['notes'] ?? $place->next_time_note ?? $place->description, 'type' => 'outing', 'status' => 'planned', 'starts_at' => $data['planned_for'] . ' 10:00:00', 'ends_at' => $data['planned_for'] . ' 12:00:00', 'timezone' => 'Europe/Prague', 'place_name' => $place->name, 'latitude' => $place->latitude, 'longitude' => $place->longitude, 'color' => '#0ea5e9']);
        $event->participants()->syncWithoutDetaching([$request->user()->id => ['role' => 'owner', 'response' => 'accepted']]);
        if (!empty($data['reservation_reference']) || !empty($data['reservation_url'])) $event->attachments()->create(['kind' => 'reservation', 'label' => 'Rezervace · ' . $place->name, 'reference_code' => $data['reservation_reference'] ?? null, 'external_url' => $data['reservation_url'] ?? null]);
        $id = DB::table('place_plans')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'place_id' => $place->id, 'gallery_space_id' => $space->id, 'created_by' => $request->user()->id, 'calendar_event_id' => $event->id, 'state' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('place_plans')->find($id), 201);
    }

    public function updatePlan(Request $request, Place $place, string $uuid): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail(); $this->authorizePlace($place, $space->id);
        $plan = DB::table('place_plans')->where('place_id', $place->id)->where('uuid', $uuid)->where('gallery_space_id', $space->id)->firstOrFail();
        $data = $request->validate(['state' => 'nullable|in:planned,visited,cancelled', 'visited_on' => 'nullable|date', 'notes' => 'nullable|string|max:5000']);
        if (($data['state'] ?? null) === 'visited' && empty($data['visited_on'])) $data['visited_on'] = now()->toDateString();
        DB::table('place_plans')->where('id', $plan->id)->update($data + ['updated_at' => now()]);
        if ($plan->calendar_event_id && isset($data['state'])) CalendarEvent::where('id', $plan->calendar_event_id)->update(['status' => $data['state'] === 'visited' ? 'completed' : ($data['state'] === 'cancelled' ? 'cancelled' : 'planned'), 'updated_at' => now()]);
        return response()->json(DB::table('place_plans')->find($plan->id));
    }

    /** A completed place visit can become one shared gallery moment using its own linked photos. */
    public function createPlanMemory(Request $request, Place $place, string $uuid): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->firstOrFail(); $this->authorizePlace($place, $space->id);
        $plan = DB::table('place_plans')->where('place_id', $place->id)->where('uuid', $uuid)->where('gallery_space_id', $space->id)->firstOrFail();
        abort_unless($plan->state === 'visited', 422, 'Vzpomínku lze vytvořit až po označení návštěvy.');
        $mediaIds = $this->mediaQuery($place, $space->id)->orderBy('taken_at')->limit(30)->pluck('id')->all();
        abort_if(!$mediaIds, 422, 'K místu zatím nejsou propojené fotografie. Nejdříve je propojte přes GPS nebo přidejte fotografie.');
        $existing = DB::table('shared_memory_moments')->where('place_plan_id', $plan->id)->first();
        if (! $existing && $plan->calendar_event_id) $existing = DB::table('shared_memory_moments')->where('calendar_event_id', $plan->calendar_event_id)->first();
        $row = ['place_plan_id' => $plan->id, 'calendar_event_id' => $plan->calendar_event_id, 'gallery_space_id' => $space->id, 'created_by' => $existing?->created_by ?? $request->user()->id, 'title' => $place->name, 'note' => $plan->notes ?? $place->next_time_note ?? $place->description, 'happened_on' => $plan->visited_on ?? now()->toDateString(), 'media_item_ids' => json_encode($mediaIds), 'is_favorite' => true, 'updated_at' => now()];
        if ($existing) { DB::table('shared_memory_moments')->where('id', $existing->id)->update($row); $id = $existing->id; }
        else $id = DB::table('shared_memory_moments')->insertGetId($row + ['uuid' => (string) Str::uuid(), 'created_at' => now()]);
        return response()->json(DB::table('shared_memory_moments')->find($id), $existing ? 200 : 201);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function authorizePlace(Place $place, int $spaceId): void
    {
        if ($place->gallery_space_id && $place->gallery_space_id !== $spaceId) {
            abort(403);
        }
    }

    /**
     * Build the media query for a place (GPS radius + explicit links).
     */
    private function mediaQuery(Place $place, int $spaceId)
    {
        $linkedIds = DB::table('media_place')
            ->where('place_id', $place->id)
            ->pluck('media_item_id');

        $q = MediaItem::where('gallery_space_id', $spaceId)->whereNull('trashed_at');

        if ($place->latitude && $place->longitude) {
            $deg = ($place->radius_meters ?? 500) / 111000.0; // rough degrees

            $q->where(function ($sub) use ($linkedIds, $place, $deg) {
                $sub->whereIn('id', $linkedIds)
                    ->orWhere(function ($gps) use ($place, $deg) {
                        $gps->whereNotNull('latitude')
                            ->whereRaw('ABS(latitude  - ?) < ?', [$place->latitude,  $deg])
                            ->whereRaw('ABS(longitude - ?) < ?', [$place->longitude, $deg * 1.5]);
                    });
            });
        } else {
            $q->whereIn('id', $linkedIds);
        }

        return $q;
    }

    /**
     * Enrich a place object with visit statistics.
     */
    private function withStats(Place $place, int $spaceId): array
    {
        $stats = $this->mediaQuery($place, $spaceId)
            ->selectRaw('
                COUNT(*)                        AS photo_count,
                COUNT(DISTINCT DATE(taken_at))  AS visit_count,
                MIN(taken_at)                   AS first_visit,
                MAX(taken_at)                   AS last_visit
            ')
            ->first();

        $albumCount = Album::where('gallery_space_id', $spaceId)
            ->whereHas('places', fn($q) => $q->where('places.id', $place->id))
            ->count();

        // Cover: first photo thumbnail
        $coverThumb = null;
        $firstPhoto = $this->mediaQuery($place, $spaceId)
            ->with('variants')
            ->orderBy('taken_at')
            ->first();
        $coverThumb = $firstPhoto?->thumbnail_url;

        return [
            'id'            => $place->id,
            'name'          => $place->name,
            'type'          => $place->type ?? 'custom',
            'country'       => $place->country,
            'country_code'  => $place->country_code,
            'city'          => $place->city,
            'address'       => $place->address,
            'latitude'      => $place->latitude,
            'longitude'     => $place->longitude,
            'radius_meters' => $place->radius_meters ?? 500,
            'description'   => $place->description,
            'website_url'   => $place->website_url,
            'osm_id'        => $place->osm_id,
            'is_rain_friendly' => (bool) $place->is_rain_friendly,
            'is_accessible' => (bool) $place->is_accessible,
            'is_photogenic' => (bool) $place->is_photogenic,
            'opens_early' => (bool) $place->opens_early,
            'price_level' => $place->price_level,
            'estimated_visit_minutes' => $place->estimated_visit_minutes,
            'personal_rating' => $place->personal_rating,
            'next_time_note' => $place->next_time_note,
            'photo_count'   => (int) ($stats->photo_count ?? 0),
            'visit_count'   => (int) ($stats->visit_count ?? 0),
            'first_visit'   => $stats->first_visit ?? null,
            'last_visit'    => $stats->last_visit ?? null,
            'album_count'   => $albumCount,
            'cover_thumb'   => $coverThumb,
        ];
    }
}
