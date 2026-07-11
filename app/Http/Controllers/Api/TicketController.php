<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TicketController extends Controller
{
    /**
     * GET /api/v1/tickets/search
     * Unified ticket search across RegioJet, FlixBus, + IDOS static link for ČD.
     */
    public function search(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from'   => 'required|string|max:120',
            'to'     => 'required|string|max:120',
            'date'   => 'required|date_format:Y-m-d',
            'adults' => 'nullable|integer|min:1|max:9',
        ]);

        $adults   = (int) ($v['adults'] ?? 1);
        $cacheKey = 'tickets:v2:' . md5("{$v['from']}|{$v['to']}|{$v['date']}|{$adults}");

        $result = Cache::remember($cacheKey, 3600 * 2, function () use ($v, $adults) {
            $trips = [];

            try {
                $trips = array_merge($trips, $this->regiojetTrips($v['from'], $v['to'], $v['date'], $adults));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug('RegioJet search failed: ' . $e->getMessage());
            }

            try {
                $trips = array_merge($trips, $this->flixbusTrips($v['from'], $v['to'], $v['date'], $adults));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug('FlixBus search failed: ' . $e->getMessage());
            }

            // Public transport APIs change frequently. Keep every provider usable
            // even when a live endpoint is unavailable or returns 404.
            if (! collect($trips)->contains(fn ($trip) => str_contains($trip['carrier'], 'RegioJet'))) {
                $trips[] = $this->portalEntry('RegioJet', '🟡', 'https://regiojet.cz/', 'Vlak i autobus');
            }
            if (! collect($trips)->contains(fn ($trip) => $trip['carrier'] === 'FlixBus')) {
                $trips[] = $this->portalEntry('FlixBus', '🟢', 'https://www.flixbus.cz/', 'Autobusové spoje');
            }
            $trips[] = $this->idosEntry($v['from'], $v['to'], $v['date'], 'vlak');
            $trips[] = $this->idosEntry($v['from'], $v['to'], $v['date'], 'autobus');
            $trips[] = $this->portalEntry('České dráhy', '🔵', 'https://www.cd.cz/spojeni-a-jizdenka/', 'Vyhledat a koupit jízdenku');
            $trips[] = $this->portalEntry('Leo Express', '⚫', 'https://www.leoexpress.com/cs/rezervace', 'Vlakové a autobusové spoje');
            $trips[] = $this->portalEntry('Omio', '🟣', 'https://www.omio.com/', 'Porovnání více dopravců');

            // Sort: live prices first (ascending), then static links last
            usort($trips, function ($a, $b) {
                $pa = $a['price'] ?? null;
                $pb = $b['price'] ?? null;
                if ($pa === null && $pb === null) return 0;
                if ($pa === null) return 1;
                if ($pb === null) return -1;
                return $pa <=> $pb;
            });

            return $trips;
        });

        return response()->json($result);
    }

    // ─── RegioJet ──────────────────────────────────────────────────────────

    private function regiojetTrips(string $from, string $to, string $date, int $adults): array
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

        $fromId = $this->fuzzyCity($cities, $from);
        $toId   = $this->fuzzyCity($cities, $to);

        if (! $fromId || ! $toId || $fromId === $toId) {
            return [];
        }

        $url = 'https://brn-ybus-pubapi.sa.cz/restapi/routes/search/simple?' . http_build_query([
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

        $trips = [];
        foreach ($data['routes'] as $route) {
            $price = $route['priceFrom'] ?? null;
            if ($price === null) {
                continue;
            }

            $dep   = $route['departureTime'] ?? null;
            $arr   = $route['arrivalTime']   ?? null;
            $dur   = ($dep && $arr)
                ? (int) round((strtotime($arr) - strtotime($dep)) / 60)
                : null;

            $isTraIn = in_array('TRAIN', $route['vehicleTypes'] ?? []);
            $seats   = $route['freeSeatsCount'] ?? null;
            $bookUrl = 'https://regiojet.cz/';

            $trips[] = [
                'carrier'       => $isTraIn ? 'RegioJet vlak' : 'RegioJet Bus',
                'icon'          => '🟡',
                'departure'     => $dep,
                'arrival'       => $arr,
                'duration_min'  => $dur,
                'price'         => (int) ceil((float) $price) * $adults,
                'price_per_pax' => (int) ceil((float) $price),
                'currency'      => 'CZK',
                'seats'         => $seats,
                'transfers'     => $route['transfersCount'] ?? 0,
                'source'        => 'live',
                'book_url'      => $bookUrl,
            ];
        }

        // Limit to 6 cheapest departures
        usort($trips, fn($a, $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX));

        return array_slice($trips, 0, 6);
    }

    // ─── FlixBus ───────────────────────────────────────────────────────────

    private function flixbusTrips(string $from, string $to, string $date, int $adults): array
    {
        $fromCity = $this->flixbusCity($from);
        $toCity   = $this->flixbusCity($to);

        if (! $fromCity || ! $toCity) {
            return [];
        }

        $url = 'https://global.api.flixbus.com/search/service/v4/search?' . http_build_query([
            'from_city_id'   => $fromCity['id'],
            'to_city_id'     => $toCity['id'],
            'departure_date' => $date,
            'number_adult'   => $adults,
            'currency'       => 'CZK',
            'locale'         => 'cs',
        ]);

        $resp = $this->curlFetch($url, ['Accept: application/json']);
        $data = json_decode($resp, true);

        // API wraps data differently per version
        $trips = $data['trips'] ?? $data['available']['trips'] ?? [];

        if (empty($trips)) {
            return [];
        }

        $results = [];
        foreach ($trips as $trip) {
            $dep   = $trip['departure']['timestamp'] ?? null;
            $arr   = $trip['arrival']['timestamp']   ?? null;
            $price = $trip['available']['lowest_price']['amount']
                ?? $trip['min_price']['amount']
                ?? null;

            if ($price === null) {
                continue;
            }

            $dur = ($dep && $arr) ? (int) round(($arr - $dep) / 60) : null;

            $bookUrl = 'https://www.flixbus.cz/';

            $results[] = [
                'carrier'       => 'FlixBus',
                'icon'          => '🟢',
                'departure'     => $dep ? date('c', $dep) : null,
                'arrival'       => $arr ? date('c', $arr) : null,
                'duration_min'  => $dur,
                'price'         => (int) ceil((float) $price),
                'price_per_pax' => (int) ceil((float) $price / max(1, $adults)),
                'currency'      => 'CZK',
                'seats'         => $trip['available']['seats'] ?? null,
                'transfers'     => $trip['transfer_count'] ?? 0,
                'source'        => 'live',
                'book_url'      => $bookUrl,
            ];
        }

        usort($results, fn($a, $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX));

        return array_slice($results, 0, 6);
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

            return (! empty($data) && isset($data[0]['id'])) ? ['id' => $data[0]['id'], 'name' => $data[0]['name'] ?? $name] : null;
        });
    }

    // ─── ČD / IDOS (static search links) ──────────────────────────────────

    private function idosEntry(string $from, string $to, string $date, string $type = 'vlak'): array
    {
        $f = urlencode($from);
        $t = urlencode($to);

        $url = $type === 'vlak'
            ? "https://idos.idnes.cz/vlak/spojeni/?f={$f}&t={$t}&date={$date}&time=0600"
            : "https://idos.idnes.cz/autobus/spojeni/?f={$f}&t={$t}&date={$date}&time=0600";

        return [
            'carrier'      => $type === 'vlak' ? 'České dráhy (IDOS)' : 'Autobus (IDOS)',
            'icon'         => $type === 'vlak' ? '🚂' : '🚌',
            'departure'    => null,
            'arrival'      => null,
            'duration_min' => null,
            'price'        => null,
            'currency'     => 'CZK',
            'seats'        => null,
            'transfers'    => null,
            'source'       => 'link',
            'note'         => 'Vyhledat na IDOS.cz →',
            'book_url'     => $url,
        ];
    }

    private function portalEntry(string $carrier, string $icon, string $url, string $note): array
    {
        return ['carrier' => $carrier, 'icon' => $icon, 'departure' => null, 'arrival' => null,
            'duration_min' => null, 'price' => null, 'currency' => 'CZK', 'seats' => null,
            'transfers' => null, 'source' => 'link', 'note' => $note . ' →', 'book_url' => $url];
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function fuzzyCity(array $cities, string $name): ?int
    {
        $clean = fn(string $s) => mb_strtolower(
            preg_replace('/\s+(hlavní|hl\.|nádraží|bus|vlak|letiště|airport|centrum|město)\b.*/iu', '', trim($s)) ?? '',
            'UTF-8'
        );

        $needle = $clean($name);

        foreach ($cities as $c) {
            if ($clean($c['name']) === $needle) {
                return $c['id'];
            }
        }

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
            CURLOPT_TIMEOUT        => 10,
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
}
