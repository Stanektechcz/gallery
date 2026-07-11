<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FreeTravelDataService
{
    public const PROVIDERS = [
        'open_meteo' => ['name' => 'Open-Meteo', 'free' => true, 'credentials' => [], 'docs_url' => 'https://open-meteo.com/en/docs', 'signup_url' => 'https://open-meteo.com/'],
        'frankfurter' => ['name' => 'Frankfurter / ECB', 'free' => true, 'credentials' => [], 'docs_url' => 'https://frankfurter.dev/', 'signup_url' => 'https://frankfurter.dev/'],
        'nominatim' => ['name' => 'Nominatim / OpenStreetMap', 'free' => true, 'credentials' => ['contact_email'], 'docs_url' => 'https://operations.osmfoundation.org/policies/nominatim/', 'signup_url' => 'https://www.openstreetmap.org/'],
        'openrouteservice' => ['name' => 'OpenRouteService', 'free' => true, 'credentials' => ['api_key'], 'docs_url' => 'https://openrouteservice.org/dev/#/signup', 'signup_url' => 'https://openrouteservice.org/dev/#/signup'],
        'transportapi' => ['name' => 'TransportAPI', 'free' => true, 'credentials' => ['app_id', 'app_key'], 'docs_url' => 'https://developer.transportapi.com/', 'signup_url' => 'https://developer.transportapi.com/'],
    ];

    public function provider(string $provider): array { abort_unless(isset(self::PROVIDERS[$provider]), 404); return self::PROVIDERS[$provider]; }
    public function config(string $provider): array { return IntegrationSetting::where('provider', $provider)->first()?->config() ?? []; }
    public function enabled(string $provider): bool { return self::PROVIDERS[$provider]['credentials'] === [] || (bool) IntegrationSetting::where('provider', $provider)->value('is_enabled'); }

    public function weather(float $latitude, float $longitude, ?string $date = null): array
    {
        $query = ['latitude' => $latitude, 'longitude' => $longitude, 'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max', 'timezone' => 'auto'];
        if ($date) $query += ['start_date' => $date, 'end_date' => $date];
        return $this->http()->get('https://api.open-meteo.com/v1/forecast', $query)->throw()->json();
    }

    public function rate(string $base, string $quote, ?string $date = null): array
    {
        $path = $date ? "v2/rate/{$base}/{$quote}?date={$date}&providers=ECB" : "v2/rate/{$base}/{$quote}?providers=ECB";
        return $this->http()->get('https://api.frankfurter.dev/' . $path)->throw()->json();
    }

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'driving-car'): array
    {
        $config = $this->config('openrouteservice');
        abort_unless($this->enabled('openrouteservice') && !empty($config['api_key']), 424, 'OpenRouteService není aktivován.');
        return $this->http()->withToken($config['api_key'])->post("https://api.openrouteservice.org/v2/directions/{$profile}/geojson", ['coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]]])->throw()->json();
    }

    public function test(string $provider): void
    {
        match ($provider) {
            'open_meteo' => $this->weather(50.0755, 14.4378),
            'frankfurter' => $this->rate('EUR', 'CZK'),
            'nominatim' => $this->http()->withHeaders(['User-Agent' => $this->nominatimAgent()])->get('https://nominatim.openstreetmap.org/search', ['q' => 'Praha', 'format' => 'jsonv2', 'limit' => 1])->throw(),
            'openrouteservice' => $this->route(50.0755, 14.4378, 49.1951, 16.6068),
            'transportapi' => $this->testTransportApi(),
        };
    }

    private function testTransportApi(): void
    {
        $config = $this->config('transportapi');
        abort_unless($this->enabled('transportapi') && !empty($config['app_id']) && !empty($config['app_key']), 424, 'TransportAPI není aktivováno.');
        $this->http()->get('https://transportapi.com/v3/uk/places.json', ['query' => 'London', 'app_id' => $config['app_id'], 'app_key' => $config['app_key']])->throw();
    }

    private function nominatimAgent(): string { return 'StanektechGallery/1.0 (' . ($this->config('nominatim')['contact_email'] ?? 'admin@example.invalid') . ')'; }
    private function http(): PendingRequest { return Http::acceptJson()->timeout(8)->retry(1, 200); }
}
