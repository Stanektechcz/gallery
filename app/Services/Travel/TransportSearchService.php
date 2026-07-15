<?php

namespace App\Services\Travel;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One safe transport gateway for the whole application.
 *
 * It deliberately does not scrape checkout pages. Timetables come from the
 * open MOTIS/Transitous API, RegioJet prices from its documented public API,
 * and every provider retains a deterministic deep-link fallback.
 */
class TransportSearchService
{
    private const NON_TRANSIT_MODES = ['WALK', 'FOOT', 'BIKE', 'CAR', 'CAR_PARKING', 'CAR_DROPOFF'];

    public function search(array $input): array
    {
        $input = $this->defaults($input);
        if (! $this->hasCoordinates($input) && config('gallery.transport.transitous_enabled', true) && ! app()->environment('testing')) {
            $input = $this->resolveCoordinates($input);
        }
        $cacheKey = 'transport:v4:' . hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return Cache::remember($cacheKey, now()->addMinutes((int) config('gallery.transport.cache_minutes', 15)), function () use ($input) {
            $results = [];

            if ($this->hasCoordinates($input) && config('gallery.transport.transitous_enabled', true)) {
                try {
                    $results = array_merge($results, $this->transitousTrips($input));
                } catch (\Throwable $exception) {
                    Log::warning('Transitous transport search failed', ['message' => $exception->getMessage()]);
                }
            }

            if (config('gallery.transport.regiojet_enabled', true) && in_array($input['mode'], ['all', 'train', 'bus'], true)) {
                try {
                    $results = array_merge($results, $this->regiojetTrips($input));
                } catch (\Throwable $exception) {
                    Log::info('RegioJet transport search unavailable', ['message' => $exception->getMessage()]);
                }
            }

            $results = array_merge($results, $this->fallbackEntries($input, $results));
            $results = $this->filterAndRank($results, $input);

            return array_values($results);
        });
    }

    private function defaults(array $input): array
    {
        return array_merge([
            'adults' => 1,
            'time' => '08:00',
            'mode' => 'all',
            'max_transfers' => 4,
            'min_transfer_minutes' => 5,
            'wheelchair' => false,
            'bike' => false,
        ], $input);
    }

    private function hasCoordinates(array $input): bool
    {
        return isset($input['from_lat'], $input['from_lng'], $input['to_lat'], $input['to_lng']);
    }

    /** Resolve simple text inputs so embedded trip planners also get schedules. */
    private function resolveCoordinates(array $input): array
    {
        foreach (['from', 'to'] as $field) {
            try {
                $place = Cache::remember('transport:geocode:' . hash('sha256', Str::lower($input[$field])), now()->addDays(14), function () use ($input, $field) {
                    $matches = $this->client(5)->get((string) config('gallery.transport.transitous_geocode_url', 'https://api.transitous.org/api/v1/geocode'), [
                        'text' => $input[$field], 'language' => 'cs', 'numResults' => 4,
                    ])->throw()->json();
                    return collect($matches)->first(fn ($item) => is_array($item) && isset($item['lat'], $item['lon']));
                });
                if ($place) {
                    $input["{$field}_lat"] = (float) $place['lat'];
                    $input["{$field}_lng"] = (float) $place['lon'];
                }
            } catch (\Throwable $exception) {
                Log::info('Transport geocoding unavailable', ['field' => $field, 'message' => $exception->getMessage()]);
            }
        }
        return $input;
    }

    private function transitousTrips(array $input): array
    {
        $params = [
            'fromPlace' => $this->coordinate($input['from_lat'], $input['from_lng']),
            'toPlace' => $this->coordinate($input['to_lat'], $input['to_lng']),
            'time' => Carbon::createFromFormat('Y-m-d H:i', "{$input['date']} {$input['time']}", 'Europe/Prague')->toIso8601String(),
            'maxTransfers' => (int) $input['max_transfers'],
            'minTransferTime' => (int) $input['min_transfer_minutes'],
            'transitModes' => $this->transitousModes($input['mode']),
            'directModes' => 'WALK',
            'detailedLegs' => false,
            'detailedTransfers' => false,
            'numItineraries' => 6,
            'maxItineraries' => 8,
            'searchWindow' => 3600,
            'language' => 'cs',
            'requireBikeTransport' => (bool) $input['bike'],
            'pedestrianProfile' => $input['wheelchair'] ? 'WHEELCHAIR' : 'FOOT',
        ];

        $response = $this->client((int) config('gallery.transport.transitous_timeout', 8))
            ->get((string) config('gallery.transport.transitous_url', 'https://api.transitous.org/api/v6/plan'), $params)
            ->throw()
            ->json();

        return collect($response['itineraries'] ?? [])->take(8)->map(function (array $itinerary) use ($input) {
            $legs = collect($itinerary['legs'] ?? []);
            $transitLegs = $legs->reject(fn (array $leg) => in_array(strtoupper((string) ($leg['mode'] ?? '')), self::NON_TRANSIT_MODES, true))->values();
            $modes = $transitLegs->pluck('mode')->filter()->map(fn ($mode) => strtoupper((string) $mode))->unique()->values();
            $services = $transitLegs->map(function (array $leg) {
                return trim(collect([$leg['agencyName'] ?? null, $leg['displayName'] ?? $leg['routeShortName'] ?? null])->filter()->unique()->join(' '));
            })->filter()->unique()->values();
            $primaryMode = $this->applicationMode((string) ($modes->first() ?? 'TRANSIT'));
            $isRealtime = $transitLegs->contains(fn (array $leg) => (bool) ($leg['realTime'] ?? false));
            $cancelled = $transitLegs->contains(fn (array $leg) => (bool) ($leg['cancelled'] ?? false));

            return $this->identified([
                'carrier' => $services->isNotEmpty() ? $services->take(3)->join(' · ') : 'Veřejná doprava',
                'provider' => 'Transitous',
                'icon' => $this->modeIcon($primaryMode),
                'mode' => $primaryMode,
                'modes' => $modes->map(fn ($mode) => $this->applicationMode($mode))->unique()->values()->all(),
                'departure' => $itinerary['startTime'] ?? null,
                'arrival' => $itinerary['endTime'] ?? null,
                'duration_min' => isset($itinerary['duration']) ? (int) ceil(((int) $itinerary['duration']) / 60) : null,
                'price' => null,
                'price_per_pax' => null,
                'currency' => 'CZK',
                'seats' => null,
                'transfers' => isset($itinerary['transfers']) ? (int) $itinerary['transfers'] : null,
                'source' => 'schedule',
                'data_source' => 'Transitous / MOTIS',
                'provider_status' => $cancelled ? 'cancelled' : ($isRealtime ? 'realtime' : 'timetable'),
                'is_realtime' => $isRealtime,
                'cancelled' => $cancelled,
                'note' => $cancelled ? 'Spoj je podle zdroje zrušený.' : ($isRealtime ? 'Jízdní řád obsahuje aktuální provozní data.' : 'Časy podle zveřejněného jízdního řádu.'),
                'book_url' => $this->idosUrl($input, $primaryMode === 'bus' ? 'autobus' : 'vlak'),
                'legs' => $transitLegs->map(fn (array $leg) => [
                    'mode' => $this->applicationMode((string) ($leg['mode'] ?? 'TRANSIT')),
                    'service' => $leg['displayName'] ?? $leg['routeShortName'] ?? null,
                    'agency' => $leg['agencyName'] ?? null,
                    'from' => data_get($leg, 'from.name'),
                    'to' => data_get($leg, 'to.name'),
                    'departure' => $leg['startTime'] ?? null,
                    'arrival' => $leg['endTime'] ?? null,
                    'realtime' => (bool) ($leg['realTime'] ?? false),
                ])->all(),
                'attribution_url' => 'https://transitous.org/sources/',
            ]);
        })->all();
    }

    private function transitousModes(string $mode): string
    {
        return match ($mode) {
            'train' => 'RAIL',
            'bus' => 'BUS,COACH',
            'tram' => 'TRAM',
            'metro' => 'SUBWAY',
            'ferry' => 'FERRY',
            default => 'TRANSIT',
        };
    }

    private function regiojetTrips(array $input): array
    {
        $cities = Cache::remember('rj_cities_v2', now()->addDays(7), function () {
            $response = $this->client(6)
                ->withHeaders(['X-Currency' => 'CZK'])
                ->get('https://brn-ybus-pubapi.sa.cz/restapi/consts/locations', ['locale' => 'cs'])
                ->throw()->json();

            return $this->extractCities($response);
        });
        $fromId = $this->fuzzyCity($cities, $input['from']);
        $toId = $this->fuzzyCity($cities, $input['to']);
        if (! $fromId || ! $toId || $fromId === $toId) return [];

        $response = $this->client(7)->withHeaders(['X-Currency' => 'CZK'])
            ->get('https://brn-ybus-pubapi.sa.cz/restapi/routes/search/simple', [
                'tariffs' => 'REGULAR', 'toLocationType' => 'CITY', 'toLocationId' => $toId,
                'fromLocationType' => 'CITY', 'fromLocationId' => $fromId,
                'departureDate' => $input['date'], 'locale' => 'cs',
            ])->throw()->json();

        return collect($response['routes'] ?? [])->filter(fn (array $route) => isset($route['priceFrom']))
            ->map(function (array $route) use ($input) {
                $vehicleTypes = collect($route['vehicleTypes'] ?? [])->map(fn ($mode) => strtoupper((string) $mode));
                $mode = $vehicleTypes->contains('TRAIN') ? 'train' : 'bus';
                $perPassenger = (float) $route['priceFrom'];
                $departure = $route['departureTime'] ?? null; $arrival = $route['arrivalTime'] ?? null;
                return $this->identified([
                    'carrier' => $mode === 'train' ? 'RegioJet vlak' : 'RegioJet autobus', 'provider' => 'RegioJet',
                    'icon' => '🟡', 'mode' => $mode, 'modes' => [$mode], 'departure' => $departure, 'arrival' => $arrival,
                    'duration_min' => $departure && $arrival ? max(0, (int) round((strtotime($arrival) - strtotime($departure)) / 60)) : null,
                    'price' => (int) ceil($perPassenger * (int) $input['adults']), 'price_per_pax' => (int) ceil($perPassenger), 'currency' => 'CZK',
                    'seats' => $route['freeSeatsCount'] ?? null, 'transfers' => (int) ($route['transfersCount'] ?? 0),
                    'source' => 'live', 'data_source' => 'RegioJet Public API', 'provider_status' => 'live_price', 'is_realtime' => false, 'cancelled' => false,
                    'note' => 'Aktuální cena od; konečnou dostupnost ověří prodejce.', 'book_url' => 'https://regiojet.cz/', 'legs' => [],
                ]);
            })->filter(fn (array $trip) => $input['mode'] === 'all' || $trip['mode'] === $input['mode'])
            ->sortBy('price')->take(6)->values()->all();
    }

    private function extractCities(mixed $node): array
    {
        $cities = [];
        $walk = function (mixed $value) use (&$walk, &$cities): void {
            if (! is_array($value)) return;
            if (isset($value['id'], $value['name']) && (! isset($value['type']) || strtoupper((string) $value['type']) === 'CITY')) {
                $cities[(string) $value['id']] = ['id' => (int) $value['id'], 'name' => (string) $value['name']];
            }
            foreach ($value as $child) if (is_array($child)) $walk($child);
        };
        $walk($node);
        return array_values($cities);
    }

    private function fallbackEntries(array $input, array $existing): array
    {
        $providers = collect($existing)->pluck('provider')->all();
        $fallback = [];
        if (! in_array('RegioJet', $providers, true) && in_array($input['mode'], ['all', 'train', 'bus'], true)) $fallback[] = $this->portalEntry('RegioJet', 'RegioJet', '🟡', 'train_bus', 'https://regiojet.cz/', 'Vyhledat vlak nebo autobus u dopravce');
        if (in_array($input['mode'], ['all', 'bus'], true)) $fallback[] = $this->portalEntry('FlixBus', 'FlixBus', '🟢', 'bus', 'https://www.flixbus.cz/', 'Vyhledat autobus u dopravce');
        if (in_array($input['mode'], ['all', 'train'], true)) {
            $fallback[] = $this->idosEntry($input, 'vlak');
            $fallback[] = $this->portalEntry('České dráhy', 'České dráhy', '🔵', 'train', 'https://www.cd.cz/spojeni-a-jizdenka/', 'Vyhledat a koupit jízdenku');
            $fallback[] = $this->portalEntry('Leo Express', 'Leo Express', '⚫', 'train', 'https://www.leoexpress.com/cs/rezervace', 'Vyhledat vlak nebo autobus');
        }
        if (in_array($input['mode'], ['all', 'bus'], true)) $fallback[] = $this->idosEntry($input, 'autobus');
        $fallback[] = $this->portalEntry('Omio', 'Omio', '🟣', 'comparison', 'https://www.omio.com/', 'Porovnat další dopravce');
        return $fallback;
    }

    private function idosEntry(array $input, string $type): array
    {
        return $this->portalEntry($type === 'vlak' ? 'České dráhy (IDOS)' : 'Autobus (IDOS)', 'IDOS', $type === 'vlak' ? '🚂' : '🚌', $type === 'vlak' ? 'train' : 'bus', $this->idosUrl($input, $type), 'Otevřít předvyplněné hledání spojů');
    }

    private function idosUrl(array $input, string $type): string
    {
        $time = str_replace(':', '', (string) $input['time']);
        return sprintf('https://idos.idnes.cz/%s/spojeni/?%s', $type === 'autobus' ? 'autobus' : 'vlak', http_build_query([
            'f' => $input['from'], 't' => $input['to'], 'date' => $input['date'], 'time' => $time,
        ]));
    }

    private function portalEntry(string $carrier, string $provider, string $icon, string $mode, string $url, string $note): array
    {
        return $this->identified(['carrier' => $carrier, 'provider' => $provider, 'icon' => $icon, 'mode' => $mode, 'modes' => [$mode],
            'departure' => null, 'arrival' => null, 'duration_min' => null, 'price' => null, 'price_per_pax' => null, 'currency' => 'CZK',
            'seats' => null, 'transfers' => null, 'source' => 'link', 'data_source' => $provider, 'provider_status' => 'external_search',
            'is_realtime' => false, 'cancelled' => false, 'note' => $note . ' →', 'book_url' => $url, 'legs' => []]);
    }

    private function filterAndRank(array $results, array $input): array
    {
        $filtered = collect($results)->reject(fn (array $item) => (bool) ($item['cancelled'] ?? false));
        if ($input['mode'] !== 'all') {
            $filtered = $filtered->filter(fn (array $item) => $item['mode'] === $input['mode'] || in_array($input['mode'], $item['modes'] ?? [], true));
        }
        $filtered = $filtered->sort(function (array $a, array $b) {
            $sourceRank = ['live' => 0, 'schedule' => 1, 'link' => 2];
            $rank = ($sourceRank[$a['source']] ?? 9) <=> ($sourceRank[$b['source']] ?? 9);
            if ($rank !== 0) return $rank;
            if ($a['price'] !== null || $b['price'] !== null) return ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX);
            return ($a['departure'] ?? 'z') <=> ($b['departure'] ?? 'z');
        })->values();

        $recommended = $filtered->first(fn (array $item) => in_array($item['source'], ['live', 'schedule'], true));
        return $filtered->map(function (array $item) use ($recommended) {
            $item['is_recommended'] = $recommended && $item['result_id'] === $recommended['result_id'];
            return $item;
        })->all();
    }

    private function identified(array $result): array
    {
        $result['result_id'] = substr(hash('sha256', implode('|', [
            $result['provider'], $result['carrier'], $result['departure'] ?? '', $result['arrival'] ?? '', $result['price'] ?? '',
        ])), 0, 24);
        return $result;
    }

    private function client(int $timeout): PendingRequest
    {
        $contact = (string) config('gallery.transport.contact', config('app.url'));
        return Http::acceptJson()->timeout($timeout)->connectTimeout(3)->withUserAgent("MakiGallery/2026 (+{$contact})");
    }

    private function fuzzyCity(array $cities, string $name): ?int
    {
        $needle = $this->normalizedCity($name);
        foreach ($cities as $city) if ($this->normalizedCity((string) $city['name']) === $needle) return (int) $city['id'];
        foreach ($cities as $city) {
            $candidate = $this->normalizedCity((string) $city['name']);
            if (str_starts_with($candidate, $needle) || str_starts_with($needle, $candidate)) return (int) $city['id'];
        }
        return null;
    }

    private function normalizedCity(string $value): string
    {
        $value = preg_replace('/\s+(hlavní|hl\.|nádraží|bus|vlak|letiště|airport|centrum|město)\b.*/iu', '', trim($value)) ?? '';
        return Str::lower(Str::ascii($value));
    }

    private function coordinate(float|int|string $lat, float|int|string $lng): string
    {
        return number_format((float) $lat, 6, '.', '') . ',' . number_format((float) $lng, 6, '.', '');
    }

    private function applicationMode(string $mode): string
    {
        return match (strtoupper($mode)) {
            'BUS', 'COACH', 'DEBUG_BUS_ROUTE' => 'bus',
            'TRAM' => 'tram', 'SUBWAY', 'METRO' => 'metro', 'FERRY', 'DEBUG_FERRY_ROUTE' => 'ferry',
            'AIRPLANE' => 'flight', 'WALK', 'FOOT' => 'walk', 'BIKE' => 'bike', 'CAR', 'CAR_PARKING', 'CAR_DROPOFF' => 'car',
            default => 'train',
        };
    }

    private function modeIcon(string $mode): string
    {
        return match ($mode) { 'bus' => '🚌', 'tram' => '🚋', 'metro' => '🚇', 'ferry' => '⛴️', 'flight' => '✈️', 'bike' => '🚲', 'car' => '🚗', default => '🚆' };
    }
}
