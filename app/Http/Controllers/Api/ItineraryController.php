<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItineraryController extends Controller
{
    /**
     * GET /api/v1/itinerary
     * Returns wishlist places + visited stats from photos.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        // Wishlist places
        $wishlist = DB::table('itinerary_places')
            ->where('gallery_space_id', $space->id)
            ->orderByRaw("FIELD(priority,'dream','soon','someday')")
            ->orderBy('name')
            ->get();

        // Visited places from GPS data (cluster by ~1° grid)
        $visitedFromPhotos = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("
                ROUND(latitude, 1) as lat_grid,
                ROUND(longitude, 1) as lng_grid,
                AVG(latitude)  as latitude,
                AVG(longitude) as longitude,
                COUNT(*) as photo_count,
                MIN(taken_at) as first_visit,
                MAX(taken_at) as last_visit,
                camera_make
            ")
            ->groupByRaw("ROUND(latitude, 1), ROUND(longitude, 1)")
            ->orderByDesc('photo_count')
            ->limit(500)
            ->get();

        // Country stats (rough estimate from coordinates)
        $countryCount = DB::table('media_items')
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->selectRaw('COUNT(DISTINCT ROUND(latitude, 0), ROUND(longitude, 0)) as unique_areas')
            ->value('unique_areas') ?? 0;

        return response()->json([
            'wishlist'      => $wishlist,
            'visited_areas' => $visitedFromPhotos,
            'stats'         => [
                'wishlist_count'  => $wishlist->count(),
                'visited_count'   => $visitedFromPhotos->count(),
                'dream_count'     => $wishlist->where('priority', 'dream')->count(),
                'done_count'      => $wishlist->where('visited', true)->count(),
            ],
        ]);
    }

    /**
     * POST /api/v1/itinerary
     * Add a place to wishlist.
     */
    public function store(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'country'      => 'nullable|string|max:100',
            'country_code' => 'nullable|string|max:3',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'category'     => 'nullable|in:country,city,landmark,restaurant,museum,nature,other',
            'notes'        => 'nullable|string|max:2000',
            'priority'     => 'nullable|in:dream,soon,someday',
        ]);

        $id = DB::table('itinerary_places')->insertGetId(array_merge([
            'gallery_space_id' => $space->id,
            'created_by'       => $user->id,
            'visited'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $validated));

        return response()->json(DB::table('itinerary_places')->find($id), 201);
    }

    /**
     * PATCH /api/v1/itinerary/{id}
     * Update a place (mark as visited, change priority, etc.).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $validated = $request->validate([
            'visited'    => 'nullable|boolean',
            'visited_at' => 'nullable|date',
            'priority'   => 'nullable|in:dream,soon,someday',
            'notes'      => 'nullable|string|max:2000',
        ]);

        if (isset($validated['visited']) && $validated['visited'] && !isset($validated['visited_at'])) {
            $validated['visited_at'] = now()->toDateString();
        }

        DB::table('itinerary_places')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(DB::table('itinerary_places')->find($id));
    }

    /**
     * DELETE /api/v1/itinerary/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        DB::table('itinerary_places')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * GET /api/v1/itinerary/check-visited
     * Check which wishlist places have nearby photos (auto-detect visited).
     */
    public function checkVisited(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $wishlist = DB::table('itinerary_places')
            ->where('gallery_space_id', $space->id)
            ->where('visited', false)
            ->whereNotNull('latitude')
            ->get();

        $updated = 0;
        foreach ($wishlist as $place) {
            // Check if any photo is within ~50km (≈0.45 degrees)
            $nearby = MediaItem::where('gallery_space_id', $space->id)
                ->whereNull('trashed_at')
                ->whereNotNull('latitude')
                ->whereRaw("ABS(latitude - ?) < 0.45 AND ABS(longitude - ?) < 0.45", [$place->latitude, $place->longitude])
                ->first(['taken_at']);

            if ($nearby) {
                DB::table('itinerary_places')
                    ->where('id', $place->id)
                    ->update([
                        'visited'    => true,
                        'visited_at' => $nearby->taken_at ? substr($nearby->taken_at, 0, 10) : now()->toDateString(),
                        'updated_at' => now(),
                    ]);
                $updated++;
            }
        }

        return response()->json(['auto_detected' => $updated]);
    }
}
