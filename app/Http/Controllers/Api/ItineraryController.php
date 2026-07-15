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

        // Wishlist places — cast lat/lng to float (MySQL DECIMAL comes back as string via PDO)
        $wishlist = DB::table('itinerary_places')
            ->where('gallery_space_id', $space->id)
            ->orderByRaw("CASE priority WHEN 'dream' THEN 1 WHEN 'soon' THEN 2 WHEN 'someday' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get()
            ->map(function ($place) {
                $place->latitude  = $place->latitude  !== null ? (float) $place->latitude  : null;
                $place->longitude = $place->longitude !== null ? (float) $place->longitude : null;
                $place->visited   = (bool) $place->visited;
                return $place;
            });

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
                MAX(taken_at) as last_visit
            ")
            ->groupByRaw("ROUND(latitude, 1), ROUND(longitude, 1)")
            ->orderByDesc('photo_count')
            ->limit(500)
            ->get()
            ->map(function ($area) {
                $area->latitude  = (float) $area->latitude;
                $area->longitude = (float) $area->longitude;
                return $area;
            });

        // Country stats (rough estimate from coordinates)
        $countryCount = DB::table('media_items')
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw('ROUND(latitude, 0) as lat_grid, ROUND(longitude, 0) as lng_grid')
            ->distinct()
            ->get()
            ->count();

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
            'description'  => 'nullable|string|max:5000',
            'website_url'  => 'nullable|url|max:512',
            'osm_id'       => 'nullable|string|max:50',
            'osm_type'     => 'nullable|string|max:20',
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
            'visited'      => 'nullable|boolean',
            'visited_at'   => 'nullable|date',
            'priority'     => 'nullable|in:dream,soon,someday',
            'notes'        => 'nullable|string|max:2000',
            'description'  => 'nullable|string|max:5000',
            'website_url'  => 'nullable|url|max:512',
            'planned_date' => 'nullable|date',
            'name'         => 'nullable|string|max:255',
            'country'      => 'nullable|string|max:100',
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

    /**
     * GET /api/v1/itinerary/search?q=...
     * Proxy Nominatim forward geocoding search (avoids CORS + enforces User-Agent policy).
     */
    public function search(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => 'required|string|min:2|max:200'])['q'];

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'format'          => 'json',
            'addressdetails'  => '1',
            'namedetails'     => '1',
            'accept-language' => 'cs,en;q=0.8',
            'limit'           => '8',
            'q'               => $q,
        ]);

        $results = $this->nominatimCurl($url);

        $mapped = array_map(function ($item) {
            $addr = $item['address'] ?? [];
            $cc   = strtoupper($addr['country_code'] ?? '');
            $type = $item['type'] ?? $item['class'] ?? 'other';

            $category = match (true) {
                in_array($type, ['city', 'town', 'village', 'municipality', 'borough'])          => 'city',
                in_array($type, ['country'])                                                       => 'country',
                in_array($type, [
                    'attraction',
                    'castle',
                    'monument',
                    'memorial',
                    'ruins',
                    'archaeological_site',
                    'landmark'
                ])                             => 'landmark',
                in_array($type, ['restaurant', 'cafe', 'bar', 'fast_food', 'pub', 'biergarten', 'food_court', 'ice_cream', 'bakery', 'nightclub']) => 'restaurant',
                in_array($type, ['museum', 'gallery', 'theatre', 'cinema'])                       => 'museum',
                in_array($type, [
                    'nature_reserve',
                    'park',
                    'forest',
                    'mountain',
                    'peak',
                    'beach',
                    'bay',
                    'island',
                    'lake'
                ])                             => 'nature',
                default                                                                            => 'other',
            };

            $names = $item['namedetails'] ?? [];
            $name = $names['name:cs'] ?? $names['name:en'] ?? $item['name']
                ?? $addr['city'] ?? $addr['town'] ?? $addr['village']
                ?? $addr['suburb'] ?? $addr['county'] ?? $addr['state']
                ?? $item['name'] ?? '';

            // Czech display_name is usually "Město, Kraj, Česká republika" - extract just first part
            $displayName = $item['display_name'] ?? '';
            if (! $name && $displayName) {
                $name = explode(',', $displayName)[0];
            }
            $name = $this->latinPlaceName((string) $name);

            return [
                'osm_id'       => (string) ($item['osm_id'] ?? ''),
                'osm_type'     => $item['osm_type'] ?? '',
                'display_name' => $displayName,
                'name'         => trim($name) ?: $displayName,
                'country'      => $this->czechCountryName($cc, $addr['country'] ?? ''),
                'country_code' => $cc,
                'city'         => $this->latinPlaceName((string) ($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? '')),
                'address'      => trim(implode(', ', array_filter([$addr['road'] ?? null, $addr['house_number'] ?? null, $addr['suburb'] ?? null]))),
                'latitude'     => (float) ($item['lat'] ?? 0),
                'longitude'    => (float) ($item['lon'] ?? 0),
                'category'     => $category,
                'type'         => $type,
            ];
        }, $results);

        return response()->json($mapped);
    }

    private function czechCountryName(string $countryCode, string $fallback): string
    {
        if ($countryCode !== '' && class_exists(\Locale::class)) {
            $localized = \Locale::getDisplayRegion('-' . $countryCode, 'cs');
            if (is_string($localized) && $localized !== '') return $localized;
        }

        return $fallback;
    }

    private function latinPlaceName(string $name): string
    {
        if ($name === '' || preg_match('/\p{Latin}/u', $name)) return $name;
        if (class_exists(\Transliterator::class)) {
            $latin = \Transliterator::create('Any-Latin')?->transliterate($name);
            if (is_string($latin) && $latin !== '') return $latin;
        }

        return $name;
    }

    /**
     * GET /api/v1/itinerary/{id}/photos
     * Return GPS photos taken near a wishlist place (within ~50km).
     */
    public function placePhotos(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $place = DB::table('itinerary_places')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->first();

        if (! $place || ! $place->latitude || ! $place->longitude) {
            return response()->json([]);
        }

        $deg = 0.45; // ~50km

        $photos = \App\Models\MediaItem::with('variants')
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->whereRaw('ABS(latitude - ?) < ? AND ABS(longitude - ?) < ?', [
                $place->latitude,
                $deg,
                $place->longitude,
                $deg
            ])
            ->orderByDesc('taken_at')
            ->limit(20)
            ->get();

        return response()->json($photos->map(fn($m) => [
            'uuid'          => $m->uuid,
            'taken_at'      => $m->taken_at,
            'thumbnail_url' => $m->thumbnail_url,
        ]));
    }

    private function nominatimCurl(string $url): array
    {
        if (! function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'MakiGallery/1.0 (gallery.stanektech.cz)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);

        if ($err || ! $resp) {
            return [];
        }

        return json_decode($resp, true) ?? [];
    }
}
