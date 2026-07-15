<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSetting;
use App\Services\Banking\GoCardlessBankDataClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FreeTravelDataService
{
    public const PROVIDERS = [
        'gocardless_bank_data' => [
            'name' => 'GoCardless Bank Account Data · Revolut', 'category' => 'finance', 'priority' => 10, 'free' => true,
            'description' => 'Bezpečné PSD2 připojení společného Revolut účtu pouze pro čtení zůstatků a transakcí.',
            'credentials' => ['secret_id', 'secret_key'],
            'credential_meta' => [
                'secret_id' => ['label' => 'Secret ID', 'type' => 'password', 'placeholder' => 'Vložte Secret ID z GoCardless', 'help' => 'Najdete v User secrets v GoCardless Bank Account Data.'],
                'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'placeholder' => 'Vložte Secret Key', 'help' => 'Klíč se uloží šifrovaně a po uložení už se nezobrazuje.'],
            ],
            'capabilities' => ['Revolut a další PSD2 banky', 'Zůstatky a historie transakcí', 'Pouze čtení – bez možnosti plateb'],
            'setup_steps' => ['Založte bezplatný účet.', 'V části User secrets vytvořte Secret ID a Secret Key.', 'Obě hodnoty uložte, aktivujte integraci a spusťte test.'],
            'docs_url' => 'https://developer.gocardless.com/bank-account-data/quick-start-guide/', 'signup_url' => 'https://bankaccountdata.gocardless.com/',
        ],
        'tmdb' => [
            'name' => 'TMDB · filmy a seriály', 'category' => 'entertainment', 'priority' => 20, 'free' => true,
            'description' => 'Globální český našeptávač filmů a seriálů včetně plakátů, popisů, hodnocení a stopáže.',
            'credentials' => ['api_key'],
            'credential_meta' => [
                'api_key' => ['label' => 'TMDB API klíč (v3 auth)', 'type' => 'password', 'placeholder' => 'Např. 32znakový API Key', 'help' => 'Použijte hodnotu API Key z nastavení účtu TMDB, ne Read Access Token.'],
            ],
            'capabilities' => ['Našeptávač filmů a seriálů', 'České názvy a popisy', 'Plakáty, hodnocení a metadata'],
            'setup_steps' => ['Vytvořte nebo otevřete účet TMDB.', 'V Settings → API požádejte o nekomerční API klíč.', 'Uložte API Key, aktivujte integraci a spusťte test.'],
            'docs_url' => 'https://developer.themoviedb.org/docs/getting-started', 'signup_url' => 'https://www.themoviedb.org/signup',
        ],
        'cinema_city' => [
            'name' => 'Cinema City · Velký Špalíček Brno', 'category' => 'entertainment', 'priority' => 30, 'free' => true,
            'description' => 'Oficiální program kina, časy projekcí, jazyk, formát, dostupnost a odkaz na rezervaci.',
            'credentials' => [], 'credential_meta' => [],
            'capabilities' => ['Denní automatická synchronizace', 'Program na 1–14 dní', 'Návrh projekce do společného kalendáře'],
            'setup_steps' => ['Nevyžaduje API klíč.', 'Spusťte test dostupnosti.', 'Tlačítkem obnovte program nebo ponechte denní automatickou synchronizaci.'],
            'docs_url' => 'https://www.cinemacity.cz/cinemas/velkyspalicek/1035', 'signup_url' => 'https://www.cinemacity.cz/cinemas/velkyspalicek/1035',
        ],
        'open_meteo' => ['name' => 'Open-Meteo', 'category' => 'travel', 'priority' => 100, 'free' => true, 'description' => 'Předpověď počasí pro cesty, akce a plánování.', 'credentials' => [], 'credential_meta' => [], 'capabilities' => ['Počasí bez API klíče'], 'setup_steps' => ['Nevyžaduje žádné nastavení.'], 'docs_url' => 'https://open-meteo.com/en/docs', 'signup_url' => 'https://open-meteo.com/'],
        'frankfurter' => ['name' => 'Frankfurter / ECB', 'category' => 'finance', 'priority' => 110, 'free' => true, 'description' => 'Referenční měnové kurzy ECB pro rozpočty cest.', 'credentials' => [], 'credential_meta' => [], 'capabilities' => ['Historické a aktuální měnové kurzy'], 'setup_steps' => ['Nevyžaduje žádné nastavení.'], 'docs_url' => 'https://frankfurter.dev/', 'signup_url' => 'https://frankfurter.dev/'],
        'nominatim' => ['name' => 'Nominatim / OpenStreetMap', 'category' => 'places', 'priority' => 120, 'free' => true, 'description' => 'Český našeptávač míst, podniků a adres.', 'credentials' => ['contact_email'], 'credential_meta' => ['contact_email' => ['label' => 'Kontaktní e-mail', 'type' => 'email', 'placeholder' => 'spravce@example.cz', 'help' => 'Veřejná služba vyžaduje identifikovat provozovatele aplikace.']], 'capabilities' => ['Místa, adresy a podniky', 'Česká lokalizace'], 'setup_steps' => ['Zadejte kontaktní e-mail správce aplikace.', 'Aktivujte integraci a spusťte test.'], 'docs_url' => 'https://operations.osmfoundation.org/policies/nominatim/', 'signup_url' => 'https://www.openstreetmap.org/'],
        'openrouteservice' => ['name' => 'OpenRouteService', 'category' => 'travel', 'priority' => 130, 'free' => true, 'description' => 'Výpočet silničních, pěších a cyklistických tras.', 'credentials' => ['api_key'], 'credential_meta' => ['api_key' => ['label' => 'API klíč', 'type' => 'password', 'placeholder' => 'Vložte OpenRouteService API klíč', 'help' => 'Bezplatný klíč získáte po registraci v dashboardu.']], 'capabilities' => ['Auto, pěšky a kolo', 'Geometrie a délka trasy'], 'setup_steps' => ['Založte bezplatný účet a vytvořte token.', 'Uložte klíč, aktivujte a otestujte.'], 'docs_url' => 'https://openrouteservice.org/dev/#/signup', 'signup_url' => 'https://openrouteservice.org/dev/#/signup'],
        'transportapi' => ['name' => 'TransportAPI', 'category' => 'travel', 'priority' => 140, 'free' => true, 'description' => 'Doplňkový zdroj dopravních dat; bezplatný tarif má především pokrytí Velké Británie.', 'credentials' => ['app_id', 'app_key'], 'credential_meta' => ['app_id' => ['label' => 'App ID', 'type' => 'text', 'placeholder' => 'Vložte App ID', 'help' => 'Identifikátor aplikace z TransportAPI dashboardu.'], 'app_key' => ['label' => 'App Key', 'type' => 'password', 'placeholder' => 'Vložte App Key', 'help' => 'Tajný klíč aplikace.']], 'capabilities' => ['Doplňková dopravní data'], 'setup_steps' => ['Založte účet a vytvořte aplikaci.', 'Uložte App ID i App Key, aktivujte a otestujte.'], 'docs_url' => 'https://developer.transportapi.com/', 'signup_url' => 'https://developer.transportapi.com/'],
    ];

    public function provider(string $provider): array
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);

        return self::PROVIDERS[$provider];
    }

    public function config(string $provider): array
    {
        return IntegrationSetting::where('provider', $provider)->first()?->config() ?? [];
    }

    public function enabled(string $provider): bool
    {
        return self::PROVIDERS[$provider]['credentials'] === [] || (bool) IntegrationSetting::where('provider', $provider)->value('is_enabled');
    }

    public function weather(float $latitude, float $longitude, ?string $date = null): array
    {
        $query = ['latitude' => $latitude, 'longitude' => $longitude, 'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max', 'timezone' => 'auto'];
        if ($date) {
            $query += ['start_date' => $date, 'end_date' => $date];
        }

        return $this->http()->get('https://api.open-meteo.com/v1/forecast', $query)->throw()->json();
    }

    public function rate(string $base, string $quote, ?string $date = null): array
    {
        $path = $date ? "v2/rate/{$base}/{$quote}?date={$date}&providers=ECB" : "v2/rate/{$base}/{$quote}?providers=ECB";

        return $this->http()->get('https://api.frankfurter.dev/'.$path)->throw()->json();
    }

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'driving-car'): array
    {
        $config = $this->config('openrouteservice');
        abort_unless($this->enabled('openrouteservice') && ! empty($config['api_key']), 424, 'OpenRouteService není aktivován.');

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
            'tmdb' => $this->testTmdb(),
            'cinema_city' => $this->testCinemaCity(),
            'gocardless_bank_data' => app(GoCardlessBankDataClient::class)->test(),
        };
    }

    private function testTmdb(): void
    {
        $config = $this->config('tmdb');
        abort_unless($this->enabled('tmdb') && ! empty($config['api_key']), 424, 'TMDB není aktivováno.');
        $this->http()->get('https://api.themoviedb.org/3/configuration', ['api_key' => $config['api_key']])->throw();
    }

    private function testCinemaCity(): void
    {
        $payload = Http::acceptJson()->withHeaders([
            'Accept-Language' => 'cs-CZ,cs;q=0.9,en;q=0.5',
            'Referer' => 'https://www.cinemacity.cz/cinemas/velkyspalicek/1035',
            'User-Agent' => 'Mozilla/5.0 (compatible; StanektechGallery/1.0; +https://gallery.stanektech.cz)',
        ])->timeout(15)->retry(2, 350)
            ->get('https://www.cinemacity.cz/cz/data-api-service/v1/quickbook/10101/film-events/in-cinema/1035/at-date/'.now('Europe/Prague')->toDateString(), ['attr' => ''])
            ->throw()->json();
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : $payload;
        abort_unless(is_array($body) && (array_key_exists('films', $body) || array_key_exists('events', $body)), 502, 'Cinema City vrátilo neznámý formát programu.');
    }

    private function testTransportApi(): void
    {
        $config = $this->config('transportapi');
        abort_unless($this->enabled('transportapi') && ! empty($config['app_id']) && ! empty($config['app_key']), 424, 'TransportAPI není aktivováno.');
        $this->http()->get('https://transportapi.com/v3/uk/places.json', ['query' => 'London', 'app_id' => $config['app_id'], 'app_key' => $config['app_key']])->throw();
    }

    private function nominatimAgent(): string
    {
        return 'StanektechGallery/1.0 ('.($this->config('nominatim')['contact_email'] ?? 'admin@example.invalid').')';
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(8)->retry(1, 200);
    }
}
