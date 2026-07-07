<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        ]);

        $key = 'tp:' . md5("{$v['from']}|{$v['to']}|{$v['date']}");

        $prices = Cache::remember($key, 3600 * 3, function () use ($v) {
            $all = [];
            try {
                $all = array_merge($all, $this->regiojetPrices($v['from'], $v['to'], $v['date']));
            } catch (\Throwable $e) {
            }
            try {
                $all = array_merge($all, $this->flixbusPrices($v['from'], $v['to'], $v['date']));
            } catch (\Throwable $e) {
            }
            usort($all, fn($a, $b) => ($a['min_price'] ?? 9999) <=> ($b['min_price'] ?? 9999));
            return $all;
        });

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
                'book_url'  => "https://www.regiojet.cz/vlaky-a-autobusy/jizdenky-online/?f={$from}&t={$to}&date={$date}",
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
                'book_url'  => "https://www.regiojet.cz/vlaky-a-autobusy/jizdenky-online/?f={$from}&t={$to}&date={$date}",
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
            'book_url'  => 'https://shop.flixbus.cz/search?departureCity=' . urlencode($from) . '&arrivalCity=' . urlencode($to) . '&rideDate=' . $date . '&adult=1',
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
        curl_close($ch);
        return ($errno || ! $resp) ? '' : $resp;
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
