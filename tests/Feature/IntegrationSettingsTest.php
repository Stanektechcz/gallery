<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_encrypted_provider_configuration_and_test_keyless_provider(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/integrations')->assertOk();
        $this->putJson('/admin/integrations/openrouteservice', ['is_enabled' => true, 'config' => ['api_key' => 'secret-route-key']])->assertOk()->assertJsonPath('is_enabled', true);
        $stored = IntegrationSetting::where('provider', 'openrouteservice')->firstOrFail();
        $this->assertNotSame('secret-route-key', $stored->encrypted_config);
        $this->assertSame('secret-route-key', $stored->config()['api_key']);
        $this->putJson('/admin/integrations/openrouteservice', ['is_enabled' => false, 'config' => []])->assertOk();
        $this->putJson('/admin/integrations/openrouteservice', ['is_enabled' => true, 'config' => []])->assertOk()->assertJsonPath('is_enabled', true);

        Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2026-07-11']]], 200)]);
        $this->postJson('/admin/integrations/open_meteo/test')->assertOk()->assertJsonPath('status', 'ok');
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
}
