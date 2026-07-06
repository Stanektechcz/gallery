<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TripController extends Controller
{
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
        ]);

        $id = DB::table('trips')->insertGetId([
            'gallery_space_id' => $space->id,
            'created_by'       => $user->id,
            'name'             => $v['name'],
            'description'      => $v['description'] ?? null,
            'start_date'       => $v['start_date'],
            'end_date'         => $v['end_date'],
            'notes'            => $v['notes'] ?? null,
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
        ]);

        $toUpdate = array_filter($v, fn($val) => $val !== null);
        $toUpdate['updated_at'] = now();

        DB::table('trips')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->update($toUpdate);

        return response()->json($this->enrichTrip(DB::table('trips')->find($id)));
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

    // ─── Waypoints ─────────────────────────────────────────────────────────

    /**
     * POST /api/v1/trips/{id}/waypoints
     */
    public function addWaypoint(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (! $this->tripBelongsToSpace($id, $space->id)) {
            return response()->json(['error' => 'not found'], 404);
        }

        $v = $request->validate([
            'place_name'       => 'required|string|max:255',
            'latitude'         => 'nullable|numeric|between:-90,90',
            'longitude'        => 'nullable|numeric|between:-180,180',
            'notes'            => 'nullable|string|max:2000',
            'arrived_at'       => 'nullable|date',
            'departed_at'      => 'nullable|date',
            'transport_mode'   => 'nullable|in:car,train,bus,plane,walk,bike,boat',
            'duration_override' => 'nullable|integer|min:0',
        ]);

        $maxOrder = DB::table('trip_waypoints')->where('trip_id', $id)->max('sort_order') ?? -1;

        // Base insert — always works, even if migration for new columns hasn't run
        $insertData = [
            'trip_id'     => $id,
            'place_name'  => $v['place_name'],
            'latitude'    => $v['latitude'] ?? null,
            'longitude'   => $v['longitude'] ?? null,
            'notes'       => $v['notes'] ?? null,
            'arrived_at'  => $v['arrived_at'] ?? null,
            'departed_at' => $v['departed_at'] ?? null,
            'sort_order'  => $maxOrder + 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ];
        // Add new columns only if migration has run
        if (\Illuminate\Support\Facades\Schema::hasColumn('trip_waypoints', 'transport_mode')) {
            $insertData['transport_mode']    = $v['transport_mode'] ?? null;
            $insertData['duration_override'] = $v['duration_override'] ?? null;
        }

        $wpId = DB::table('trip_waypoints')->insertGetId($insertData);

        $wp = DB::table('trip_waypoints')->find($wpId);
        if ($wp) {
            $wp->latitude  = $wp->latitude  !== null ? (float) $wp->latitude  : null;
            $wp->longitude = $wp->longitude !== null ? (float) $wp->longitude : null;
        }
        return response()->json($wp, 201);
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


        $wp = DB::table('trip_waypoints')->find($wpId);
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

        foreach ($v['order'] as $i => $wpId) {
            DB::table('trip_waypoints')
                ->where('id', $wpId)
                ->where('trip_id', $id)
                ->update(['sort_order' => $i, 'updated_at' => now()]);
        }

        return response()->json(['reordered' => count($v['order'])]);
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
                ->map(function ($wp) {
                    $wp->latitude  = $wp->latitude  !== null ? (float) $wp->latitude  : null;
                    $wp->longitude = $wp->longitude !== null ? (float) $wp->longitude : null;
                    return $wp;
                });
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
}
