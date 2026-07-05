<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JourneyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $events = DB::table('journey_events')
            ->where('gallery_space_id', $space->id)
            ->orderByDesc('event_date')
            ->get();

        // Enrich each event with thumbnail strip from photos on that day+location
        $enriched = $events->map(function ($event) use ($space) {
            $q = MediaItem::where('gallery_space_id', $space->id)
                ->whereNull('trashed_at')
                ->whereDate('taken_at', $event->event_date)
                ->with('variants');

            if (! empty($event->latitude) && ! empty($event->longitude)) {
                $q->whereRaw('ABS(latitude - ?) < 0.5 AND ABS(longitude - ?) < 0.5', [
                    $event->latitude,
                    $event->longitude,
                ]);
            }

            $photos = $q->orderBy('taken_at')->limit(5)->get();
            $event->photo_count = $photos->count();
            $event->thumbs      = $photos->map(fn($p) => $p->thumbnail_url)->filter()->values();
            return $event;
        });

        return response()->json($enriched);
    }

    public function store(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $v = $request->validate([
            'title'               => 'required|string|max:255',
            'story'               => 'nullable|string|max:5000',
            'event_date'          => 'required|date',
            'place_name'          => 'nullable|string|max:255',
            'place_display_name'  => 'nullable|string|max:512',
            'emotion'             => 'nullable|string|max:10',
            'song_link'           => 'nullable|url|max:512',
            'latitude'            => 'nullable|numeric|between:-90,90',
            'longitude'           => 'nullable|numeric|between:-180,180',
            'linked_itinerary_id' => 'nullable|integer',
            'source'              => 'nullable|in:manual,auto',
        ]);

        $id = DB::table('journey_events')->insertGetId([
            'gallery_space_id'    => $space->id,
            'created_by'          => $user->id,
            'title'               => $v['title'],
            'story'               => $v['story'] ?? null,
            'event_date'          => $v['event_date'],
            'place_name'          => $v['place_name'] ?? null,
            'place_display_name'  => $v['place_display_name'] ?? null,
            'emotion'             => $v['emotion'] ?? '❤️',
            'song_link'           => $v['song_link'] ?? null,
            'latitude'            => $v['latitude'] ?? null,
            'longitude'           => $v['longitude'] ?? null,
            'linked_itinerary_id' => $v['linked_itinerary_id'] ?? null,
            'source'              => $v['source'] ?? 'manual',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return response()->json(DB::table('journey_events')->find($id), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $v = $request->validate([
            'title'              => 'nullable|string|max:255',
            'story'              => 'nullable|string|max:5000',
            'event_date'         => 'nullable|date',
            'place_name'         => 'nullable|string|max:255',
            'place_display_name' => 'nullable|string|max:512',
            'emotion'            => 'nullable|string|max:10',
            'song_link'          => 'nullable|url|max:512',
            'latitude'           => 'nullable|numeric|between:-90,90',
            'longitude'          => 'nullable|numeric|between:-180,180',
        ]);

        // Only update provided (non-null) fields
        $toUpdate = array_filter($v, fn($val) => $val !== null);
        $toUpdate['updated_at'] = now();

        DB::table('journey_events')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->update($toUpdate);

        return response()->json(DB::table('journey_events')->find($id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        DB::table('journey_events')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * GET /api/v1/journey/auto-suggest
     * Cluster GPS-tagged photos by day + ~1° grid and return suggested events.
     * Skips dates/locations already covered by existing events.
     */
    public function autoSuggest(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        // Cluster by date + 1° grid cell
        $clusters = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('taken_at')
            ->selectRaw("
                DATE(taken_at)      AS visit_date,
                ROUND(latitude, 1)  AS lat_grid,
                ROUND(longitude, 1) AS lng_grid,
                AVG(latitude)       AS latitude,
                AVG(longitude)      AS longitude,
                COUNT(*)            AS photo_count,
                MIN(taken_at)       AS first_photo,
                MAX(taken_at)       AS last_photo
            ")
            ->groupByRaw("DATE(taken_at), ROUND(latitude, 1), ROUND(longitude, 1)")
            ->orderByDesc('photo_count')
            ->limit(150)
            ->get();

        // Already-covered date+location combinations from existing events
        $existing = DB::table('journey_events')
            ->where('gallery_space_id', $space->id)
            ->whereNotNull('event_date')
            ->get(['event_date', 'latitude', 'longitude']);

        $suggestions = [];
        $geocodedCount = 0;

        foreach ($clusters as $cluster) {
            $alreadyCovered = $existing->first(function ($ev) use ($cluster) {
                if ($ev->event_date !== $cluster->visit_date) {
                    return false;
                }
                // Event without GPS — match by date alone
                if (! $ev->latitude || ! $ev->longitude) {
                    return true;
                }
                $dist = abs($ev->latitude - $cluster->latitude) + abs($ev->longitude - $cluster->longitude);
                return $dist < 0.5;
            });

            if ($alreadyCovered) {
                continue;
            }

            $lat = round((float) $cluster->latitude, 5);
            $lng = round((float) $cluster->longitude, 5);

            // Reverse-geocode top 30 clusters (cached 30 days to avoid hitting rate limits)
            $placeHint = null;
            if ($geocodedCount < 30) {
                $placeHint = $this->reverseGeocode($lat, $lng);
                $geocodedCount++;
            }

            // Sample thumbnails via a separate query (max 4)
            $samplePhotos = MediaItem::with('variants')
                ->where('gallery_space_id', $space->id)
                ->whereNull('trashed_at')
                ->whereDate('taken_at', $cluster->visit_date)
                ->whereRaw('ABS(latitude - ?) < 0.2 AND ABS(longitude - ?) < 0.2', [$lat, $lng])
                ->orderBy('taken_at')
                ->limit(4)
                ->get();

            $thumbUrls = $samplePhotos->map(fn($p) => $p->thumbnail_url)->filter()->values()->toArray();

            $suggestions[] = [
                'key'         => $cluster->visit_date . '_' . round($lat, 1) . '_' . round($lng, 1),
                'visit_date'  => $cluster->visit_date,
                'latitude'    => $lat,
                'longitude'   => $lng,
                'photo_count' => (int) $cluster->photo_count,
                'first_photo' => $cluster->first_photo,
                'last_photo'  => $cluster->last_photo,
                'place_hint'  => $placeHint,
                'thumb_urls'  => $thumbUrls,
            ];
        }

        usort($suggestions, fn($a, $b) => strcmp($b['visit_date'], $a['visit_date']));

        return response()->json($suggestions);
    }

    /**
     * POST /api/v1/journey/auto-import
     * Bulk-import approved suggestion events. Auto-links to nearby itinerary places.
     */
    public function autoImport(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $v = $request->validate([
            'events'                      => 'required|array|max:50',
            'events.*.title'              => 'required|string|max:255',
            'events.*.event_date'         => 'required|date',
            'events.*.latitude'           => 'nullable|numeric',
            'events.*.longitude'          => 'nullable|numeric',
            'events.*.place_name'         => 'nullable|string|max:255',
            'events.*.place_display_name' => 'nullable|string|max:512',
            'events.*.emotion'            => 'nullable|string|max:10',
        ]);

        $inserted = 0;
        foreach ($v['events'] as $ev) {
            // Auto-link to a matching itinerary place (~50 km radius)
            $linkedId = null;
            if (! empty($ev['latitude']) && ! empty($ev['longitude'])) {
                $itin = DB::table('itinerary_places')
                    ->where('gallery_space_id', $space->id)
                    ->whereNotNull('latitude')
                    ->whereRaw('ABS(latitude - ?) < 0.5 AND ABS(longitude - ?) < 0.5', [
                        $ev['latitude'],
                        $ev['longitude'],
                    ])
                    ->first(['id']);
                $linkedId = $itin?->id;
            }

            DB::table('journey_events')->insert([
                'gallery_space_id'    => $space->id,
                'created_by'          => $user->id,
                'title'               => $ev['title'],
                'story'               => null,
                'event_date'          => $ev['event_date'],
                'place_name'          => $ev['place_name'] ?? null,
                'place_display_name'  => $ev['place_display_name'] ?? null,
                'emotion'             => $ev['emotion'] ?? '📸',
                'latitude'            => $ev['latitude'] ?? null,
                'longitude'           => $ev['longitude'] ?? null,
                'linked_itinerary_id' => $linkedId,
                'source'              => 'auto',
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            $inserted++;
        }

        return response()->json(['imported' => $inserted]);
    }

    /**
     * GET /api/v1/journey/{id}/photos
     * Photos from the same day (and location if event has GPS).
     */
    public function photos(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $event = DB::table('journey_events')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->first();

        if (! $event) {
            return response()->json(['error' => 'not found'], 404);
        }

        $q = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereDate('taken_at', $event->event_date)
            ->with('variants');

        if (! empty($event->latitude) && ! empty($event->longitude)) {
            $q->whereRaw('ABS(latitude - ?) < 0.5 AND ABS(longitude - ?) < 0.5', [
                $event->latitude,
                $event->longitude,
            ]);
        }

        $photos = $q->orderBy('taken_at')->limit(60)->get();

        return response()->json($photos->map(fn($p) => [
            'uuid'          => $p->uuid,
            'file_name'     => $p->file_name,
            'thumbnail_url' => $p->thumbnail_url,
            'taken_at'      => $p->taken_at,
            'latitude'      => $p->latitude,
            'longitude'     => $p->longitude,
        ]));
    }

    // ─── Nominatim helpers ──────────────────────────────────────────────────

    private function reverseGeocode(float $lat, float $lng): ?string
    {
        $cacheKey = 'rgc_' . round($lat, 1) . '_' . round($lng, 1);

        return Cache::remember($cacheKey, 86400 * 30, function () use ($lat, $lng) {
            $url = sprintf(
                'https://nominatim.openstreetmap.org/reverse?format=json&lat=%s&lon=%s&zoom=10',
                $lat,
                $lng
            );

            $data = $this->nominatimCurl($url);
            if (empty($data)) {
                return null;
            }

            $addr = $data['address'] ?? [];
            return $addr['city']
                ?? $addr['town']
                ?? $addr['village']
                ?? $addr['county']
                ?? $addr['state']
                ?? $addr['country']
                ?? null;
        });
    }

    private function nominatimCurl(string $url): array
    {
        if (! function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
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
