<?php

namespace App\Services\Geo;

use App\Models\GallerySpace;
use App\Models\Place;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Hledání míst — vlastní napřed, svět až potom.
 *
 * Našeptávač dosud posílal každý úhoz rovnou na Nominatim a vrátil osm holých řádků.
 * U galerie dvojice je to ale špatné pořadí otázky: většina fotek nevzniká na náhodných
 * místech světa, ale tam, kde už jednou byli — v kavárně, u rodičů, na chatě. Tahle
 * místa aplikace zná v tabulce `places`, takže se nabízejí první a s vlastními jmény,
 * která jim ti dva dali.
 *
 * Teprve když vlastní seznam nestačí, ptáme se venku.
 *
 * Odpovědi se ukládají do cache. Nominatim je zdarma a jeho podmínky žádají nejvýš
 * jeden dotaz za vteřinu — psaní do políčka jich vyrobí dvacet, a slušnost k službě,
 * na které stojíme, je levnější než hledat náhradu, až nás odstřihne.
 */
class PlaceSearchService
{
    private const AGENT = 'MakiGallery/1.0 (gallery.stanektech.cz)';
    private const CACHE_MINUTES = 60 * 24 * 7;

    /**
     * Návrhy pro rozepsaný text.
     *
     * @param array{lat?: float|null, lon?: float|null} $near Bod, kolem kterého se má hledat.
     * @return array<int, array<string, mixed>>
     */
    public function suggest(string $query, ?GallerySpace $space = null, array $near = []): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];

        // Souřadnice vlepené do políčka. Lidé je kopírují z map i ze zpráv a čekat, že
        // je někdo rozepíše na jméno místa, je zbytečná práce navíc.
        if ($bod = $this->parseCoordinates($query)) {
            return [$bod + ['source' => 'coordinates']];
        }

        $vlastni = $space ? $this->ownPlaces($query, $space) : [];

        // Dva zdroje, protože každý umí něco jiného. Nominatim je adresní a na názvech
        // podniků selhává; Photon staví nad stejnými daty OSM, ale je dělaný na
        // našeptávání a snese překlepy. Ani jeden ovšem nenajde, co v OSM nikdo nevložil —
        // od toho je možnost uložit si místo pod vlastním jménem.
        $svet = $this->nominatim($query, $near);

        if (count($svet) < 5) {
            foreach ($this->photon($query, $near) as $navic) {
                $duplicita = false;
                foreach ($svet as $uz) {
                    if ($this->distanceMeters($uz['latitude'], $uz['longitude'], $navic['latitude'], $navic['longitude']) < 120) {
                        $duplicita = true;
                        break;
                    }
                }

                if (! $duplicita) $svet[] = $navic;
            }
        }

        // Vlastní místo a stejné místo z Nominatimu se nesmí objevit dvakrát: shoda se
        // pozná podle vzdálenosti, ne podle jména, protože jméno bývá jiné právě proto,
        // že si ho ti dva pojmenovali po svém.
        $svet = array_values(array_filter($svet, function (array $venku) use ($vlastni) {
            foreach ($vlastni as $doma) {
                if ($this->distanceMeters($doma['latitude'], $doma['longitude'], $venku['latitude'], $venku['longitude']) < 120) {
                    return false;
                }
            }

            return true;
        }));

        return array_merge($vlastni, $svet);
    }

    /**
     * Adresa pro bod na mapě.
     *
     * Bez tohohle nešlo píchnout do mapy a mít u fotky napsané, kde vznikla — a přesně
     * to člověk potřebuje u snímku, kterému telefon GPS nezapsal.
     */
    public function reverse(float $latitude, float $longitude): ?array
    {
        $klic = 'geo:rev:' . round($latitude, 5) . ',' . round($longitude, 5);

        return Cache::remember($klic, now()->addMinutes(self::CACHE_MINUTES), function () use ($latitude, $longitude) {
            $odpoved = $this->call('https://nominatim.openstreetmap.org/reverse', [
                'format' => 'jsonv2',
                'addressdetails' => '1',
                'accept-language' => 'cs,en;q=0.8',
                'zoom' => '18',
                'lat' => $latitude,
                'lon' => $longitude,
            ]);

            if (! is_array($odpoved) || empty($odpoved['lat'])) return null;

            return $this->shape($odpoved);
        });
    }

    /** Místa, která už tenhle prostor zná. */
    private function ownPlaces(string $query, GallerySpace $space): array
    {
        $like = '%' . str_replace(' ', '%', $query) . '%';

        return Place::where('gallery_space_id', $space->id)
            ->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('address', 'like', $like))
            ->whereNotNull('latitude')
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Place $place) => [
                'id' => $place->id,
                'name' => $place->name,
                'label' => $place->name,
                'detail' => collect([$place->address, $place->city, $place->country])->filter()->implode(', '),
                'latitude' => (float) $place->latitude,
                'longitude' => (float) $place->longitude,
                'country' => $place->country,
                'country_code' => $place->country_code,
                'city' => $place->city,
                'address' => $place->address,
                'category' => $place->type ?: 'saved',
                'source' => 'own',
            ])->all();
    }

    /**
     * Hledání ve světě.
     *
     * `viewbox` bez `bounded` znamená „nejdřív sem, ale neschovávej zbytek" — fotka
     * z dovolené se najde i tehdy, když se hledá z domova.
     */
    private function nominatim(string $query, array $near): array
    {
        $params = [
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'namedetails' => '1',
            'accept-language' => 'cs,en;q=0.8',
            'limit' => '12',
            'q' => $query,
        ];

        if (isset($near['lat'], $near['lon']) && $near['lat'] !== null && $near['lon'] !== null) {
            $rozsah = 0.75;
            $params['viewbox'] = implode(',', [
                $near['lon'] - $rozsah, $near['lat'] + $rozsah,
                $near['lon'] + $rozsah, $near['lat'] - $rozsah,
            ]);
        }

        $klic = 'geo:search:' . md5(json_encode($params));

        $vysledky = Cache::remember($klic, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->call('https://nominatim.openstreetmap.org/search', $params));

        if (! is_array($vysledky)) return [];

        $mista = array_map(fn (array $item) => $this->shape($item), $vysledky);

        // Blíž znamená pravděpodobněji. Nominatim řadí podle své vlastní důležitosti, což
        // u „Náměstí" vrátí to největší v zemi, ne to o dvě ulice dál.
        if (isset($near['lat'], $near['lon']) && $near['lat'] !== null) {
            usort($mista, fn ($a, $b) => $this->distanceMeters($near['lat'], $near['lon'], $a['latitude'], $a['longitude'])
                <=> $this->distanceMeters($near['lat'], $near['lon'], $b['latitude'], $b['longitude']));
        }

        return $mista;
    }

    /**
     * Photon — našeptávač nad OSM.
     *
     * Doplňuje Nominatim tam, kde je slabý: u názvů podniků a u překlepů. Vrací GeoJSON,
     * takže souřadnice jsou v `geometry.coordinates` v pořadí [lon, lat] — obráceně, než
     * je člověk zvyklý číst, a záměna je tichá chyba na druhém konci světa.
     */
    private function photon(string $query, array $near): array
    {
        $params = ['q' => $query, 'lang' => 'cs', 'limit' => 6];

        if (isset($near['lat'], $near['lon']) && $near['lat'] !== null) {
            $params['lat'] = $near['lat'];
            $params['lon'] = $near['lon'];
        }

        $klic = 'geo:photon:' . md5(json_encode($params));
        $odpoved = Cache::remember($klic, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->call('https://photon.komoot.io/api/', $params));

        if (! is_array($odpoved) || empty($odpoved['features'])) return [];

        $mista = [];

        foreach ($odpoved['features'] as $feature) {
            $p = $feature['properties'] ?? [];
            $souradnice = $feature['geometry']['coordinates'] ?? null;

            if (! is_array($souradnice) || count($souradnice) < 2) continue;

            $ulice = collect([$p['street'] ?? null, $p['housenumber'] ?? null])->filter()->implode(' ');

            $mista[] = [
                'id' => null,
                'name' => $p['name'] ?? ($p['street'] ?? ''),
                'label' => $p['name'] ?? '',
                'detail' => collect([$ulice ?: null, $p['city'] ?? null, $p['country'] ?? null])->filter()->implode(', '),
                'latitude' => (float) $souradnice[1],
                'longitude' => (float) $souradnice[0],
                'country' => $p['country'] ?? null,
                'country_code' => strtoupper($p['countrycode'] ?? ''),
                'city' => $p['city'] ?? null,
                'address' => $ulice ?: null,
                'category' => $this->category(['type' => $p['osm_value'] ?? '', 'category' => $p['osm_key'] ?? '']),
                'osm_id' => $p['osm_id'] ?? null,
                'osm_type' => $p['osm_type'] ?? null,
                'source' => 'osm',
            ];
        }

        return array_values(array_filter($mista, fn ($m) => $m['name'] !== ''));
    }

    /** Jednotný tvar pro obojí — vlastní i cizí. */
    private function shape(array $item): array
    {
        $adresa = $item['address'] ?? [];

        $mesto = $adresa['city'] ?? $adresa['town'] ?? $adresa['village'] ?? $adresa['municipality'] ?? null;
        $ulice = collect([$adresa['road'] ?? null, $adresa['house_number'] ?? null])->filter()->implode(' ');

        $jmeno = $item['namedetails']['name:cs']
            ?? $item['namedetails']['name']
            ?? $item['name']
            ?? explode(',', (string) ($item['display_name'] ?? ''))[0];

        return [
            'id' => null,
            'name' => $jmeno ?: (string) ($item['display_name'] ?? ''),
            'label' => $jmeno ?: (string) ($item['display_name'] ?? ''),
            // Celá adresa pod jménem: „Kavárna" jich je v Praze třicet a bez ulice
            // se z nabídky nedá vybrat ta správná.
            'detail' => collect([$ulice ?: null, $mesto, $adresa['country'] ?? null])->filter()->implode(', ')
                ?: (string) ($item['display_name'] ?? ''),
            'latitude' => (float) ($item['lat'] ?? 0),
            'longitude' => (float) ($item['lon'] ?? 0),
            'country' => $adresa['country'] ?? null,
            'country_code' => strtoupper($adresa['country_code'] ?? ''),
            'city' => $mesto,
            'address' => $ulice ?: null,
            'category' => $this->category($item),
            'osm_id' => $item['osm_id'] ?? null,
            'osm_type' => $item['osm_type'] ?? null,
            'source' => 'osm',
        ];
    }

    /** Hrubé zařazení, aby šlo v nabídce vedle sebe poznat kavárnu od vesnice. */
    private function category(array $item): string
    {
        // jsonv2 pojmenovává třídu `category`, starší formát `class`, a u adres bývá
        // užitečnější `addresstype`. Číst jen `type` znamenalo, že skoro všechno spadlo
        // do „other" — kategorie, která nic neříká, je stejně dobrá jako žádná.
        $typ = $item['type'] ?? '';
        $trida = $item['category'] ?? $item['class'] ?? '';
        $adresni = $item['addresstype'] ?? '';

        if ($trida === 'amenity' && in_array($typ, ['cafe', 'restaurant', 'bar', 'pub', 'fast_food', 'ice_cream', 'biergarten'], true)) {
            return 'food';
        }

        if ($trida === 'tourism') {
            return in_array($typ, ['hotel', 'guest_house', 'hostel', 'apartment', 'camp_site'], true) ? 'stay' : 'landmark';
        }

        if ($trida === 'man_made' || $typ === 'bridge') return 'landmark';
        if ($trida === 'natural' || $trida === 'water' || $trida === 'leisure') return 'nature';
        if ($trida === 'shop') return 'shop';
        if ($trida === 'highway' || $adresni === 'road') return 'address';

        return match (true) {
            in_array($typ, ['city', 'town', 'village', 'hamlet', 'municipality', 'borough', 'suburb'], true) => 'city',
            $typ === 'country' => 'country',
            in_array($typ, ['restaurant', 'cafe', 'bar', 'pub', 'fast_food', 'bakery', 'ice_cream', 'biergarten'], true) => 'food',
            in_array($typ, ['museum', 'gallery', 'theatre', 'cinema', 'artwork'], true) => 'culture',
            in_array($typ, ['hotel', 'guest_house', 'hostel', 'apartment', 'camp_site'], true) => 'stay',
            in_array($typ, ['attraction', 'castle', 'monument', 'memorial', 'ruins', 'viewpoint'], true) => 'landmark',
            in_array($typ, ['park', 'forest', 'beach', 'peak', 'water', 'nature_reserve', 'bay', 'river'], true) => 'nature',
            in_array($typ, ['station', 'airport', 'bus_stop', 'aerodrome'], true) => 'transport',
            in_array($typ, ['house', 'residential', 'apartments', 'building'], true) => 'address',
            default => 'other',
        };
    }

    /**
     * Souřadnice napsané rukou.
     *
     * Bere „50.0755, 14.4378" i „50.0755 14.4378" a odmítne čísla mimo rozsah — číslo
     * nad devadesát není zeměpisná šířka, ať vypadá jakkoli.
     */
    public function parseCoordinates(string $text): ?array
    {
        if (! preg_match('/^\s*(-?\d{1,3}(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:[.,]\d+)?)\s*$/', $text, $m)) {
            return null;
        }

        $lat = (float) str_replace(',', '.', $m[1]);
        $lon = (float) str_replace(',', '.', $m[2]);

        if (abs($lat) > 90 || abs($lon) > 180) return null;

        return [
            'id' => null,
            'name' => sprintf('%.5f, %.5f', $lat, $lon),
            'label' => sprintf('%.5f, %.5f', $lat, $lon),
            'detail' => 'Souřadnice',
            'latitude' => $lat,
            'longitude' => $lon,
            'country' => null,
            'country_code' => null,
            'city' => null,
            'address' => null,
            'category' => 'coordinates',
        ];
    }

    private function call(string $url, array $params): mixed
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::AGENT])
                ->timeout(6)
                ->get($url, $params);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            // Venkovní služba, která zrovna neodpovídá, nesmí shodit našeptávač — vlastní
            // místa se nabídnou tak jako tak.
            return null;
        }
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
