<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_encrypted_provider_configuration_and_test_keyless_provider(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations')
            ->has('providers', 8)
            ->where('providers.0.provider', 'gocardless_bank_data')
            ->where('providers.0.credential_meta.secret_id.label', 'Secret ID')
            ->where('providers.1.provider', 'tmdb')
            ->where('providers.1.credential_meta.api_key.label', 'TMDB API klíč (v3 auth)')
            ->where('providers.2.provider', 'cinema_city')
        );
        $this->putJson('/admin/integrations/openrouteservice', ['is_enabled' => true, 'config' => ['api_key' => 'secret-route-key']])->assertOk()->assertJsonPath('is_enabled', true);
        $stored = IntegrationSetting::where('provider', 'openrouteservice')->firstOrFail();
        $this->assertNotSame('secret-route-key', $stored->encrypted_config);
        $this->assertSame('secret-route-key', $stored->config()['api_key']);
        $this->putJson('/admin/integrations/openrouteservice', ['is_enabled' => false, 'config' => []])->assertOk();
        $this->putJson('/admin/integrations/openrouteservice', ['is_enabled' => true, 'config' => []])->assertOk()->assertJsonPath('is_enabled', true);

        Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2026-07-11']]], 200)]);
        $this->postJson('/admin/integrations/open_meteo/test')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_admin_can_configure_gocardless_and_tmdb_and_diagnostics_never_return_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->putJson('/admin/integrations/gocardless_bank_data', [
            'is_enabled' => true,
            'config' => ['secret_id' => 'bank-secret-id', 'secret_key' => 'bank-secret-key'],
        ])->assertOk()->assertJsonPath('is_configured', true)->assertJsonPath('configured_credentials.0', 'secret_id');
        $this->putJson('/admin/integrations/tmdb', [
            'is_enabled' => true,
            'config' => ['api_key' => 'tmdb-super-secret'],
        ])->assertOk()->assertJsonPath('is_enabled', true)->assertJsonMissing(['api_key' => 'tmdb-super-secret']);

        Http::fake(['api.themoviedb.org/*' => Http::response(['status_message' => 'Invalid API key'], 401)]);
        $response = $this->postJson('/admin/integrations/tmdb/test')->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('HTTP 401', $response->json('message'));
        $this->assertStringNotContainsString('tmdb-super-secret', $response->getContent());
        $this->assertStringNotContainsString('tmdb-super-secret', IntegrationSetting::where('provider', 'tmdb')->value('last_error'));
    }

    public function test_authenticated_travel_data_endpoints_use_free_providers_through_server_side_adapter(): void
    {
        $user = User::factory()->create(['role' => 'partner']);
        $this->actingAs($user);
        Http::fake([
            'api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2026-07-11']]], 200),
            'api.frankfurter.dev/*' => Http::response(['base' => 'EUR', 'quote' => 'CZK', 'rate' => 25.12], 200),
        ]);
        $this->getJson('/api/v1/travel-data/weather?latitude=50.0755&longitude=14.4378')->assertOk()->assertJsonPath('daily.time.0', '2026-07-11');
        $this->getJson('/api/v1/travel-data/exchange-rate?base=EUR&quote=CZK')->assertOk()->assertJsonPath('rate', 25.12);
    }

    public function test_openrouteservice_uses_raw_key_and_accepts_geojson_response(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/admin/integrations/openrouteservice', [
            'is_enabled' => true,
            'config' => ['api_key' => 'secret-route-key'],
        ])->assertOk();

        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [['type' => 'Feature', 'properties' => ['summary' => ['distance' => 1, 'duration' => 1]]]],
            ], 200, ['Content-Type' => 'application/geo+json']),
        ]);

        $this->postJson('/admin/integrations/openrouteservice/test')->assertOk()->assertJsonPath('status', 'ok');
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.openrouteservice.org/v2/directions/driving-car/geojson')
                && in_array('secret-route-key', $request->header('Authorization'), true)
                && str_contains(implode(',', $request->header('Accept')), 'application/geo+json')
                && ($request->data()['language'] ?? null) === 'cs';
        });
    }
}
