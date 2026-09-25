<?php

namespace Tests\Feature\Banka;

use App\Models\BankConnection;
use App\Models\GallerySpace;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankovniPripojeniTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše finance', 'slug' => 'nase-finance-banka', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'joined_at' => now()]);
        $setting = new IntegrationSetting(['provider' => 'gocardless_bank_data', 'is_enabled' => true, 'updated_by' => $this->vlastnik->id]);
        $setting->replaceConfig(['secret_id' => 'test-id', 'secret_key' => 'test-secret']);
        $setting->save();
        $this->actingAs($this->vlastnik);
    }

    private function pripojeni(): BankConnection
    {
        return BankConnection::create(['gallery_space_id' => $this->prostor->id, 'connected_by' => $this->vlastnik->id,
            'provider' => 'gocardless', 'institution_id' => 'REVOLUT_REVOGB21', 'institution_name' => 'Revolut',
            'requisition_id' => 'requisition-tajne-1', 'agreement_id' => 'agreement-tajne-1',
            'oauth_state_hash' => hash('sha256', 'stav'), 'status' => 'pending', 'sync_enabled' => true]);
    }

    /**
     * Nepotvrzený souhlas se synchronizací neaktivuje.
     *
     * Dřív synchronizace čekajícího připojení nastavila `active`, i když
     * banka souhlas nepotvrdila, a odpověď vracela id žádosti i souhlasu.
     */
    public function test_synchronizace_nepotvrzeneho_pripojeni_ho_neaktivuje_a_neprozradi_id(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/token/new/')) {
                return Http::response(['access' => 'token', 'access_expires' => 3600]);
            }
            if (str_contains($request->url(), '/requisitions/requisition-tajne-1/')) {
                return Http::response(['id' => 'requisition-tajne-1', 'status' => 'CR', 'accounts' => []]);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });
        $connection = $this->pripojeni();

        $response = $this->postJson("/api/v1/banking/connections/{$connection->uuid}/sync");

        $this->assertContains($response->status(), [409, 422]);
        $this->assertStringNotContainsString('requisition-tajne-1', $response->getContent());
        $this->assertStringNotContainsString('agreement-tajne-1', $response->getContent());
        $this->assertSame('pending', $connection->fresh()->status);
    }

    public function test_chyba_poskytovatele_se_neukaze_ani_neulozi_doslova(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/token/new/')) {
                return Http::response(['access' => 'token', 'access_expires' => 3600]);
            }
            if (str_contains($request->url(), '/requisitions/requisition-tajne-1/')) {
                return Http::response(['id' => 'requisition-tajne-1', 'status' => 'LN', 'accounts' => ['account-1']]);
            }

            return Http::response(['summary' => 'Tajny interni detail poskytovatele'], 503);
        });
        $connection = $this->pripojeni();
        $connection->update(['status' => 'active']);

        $response = $this->postJson("/api/v1/banking/connections/{$connection->uuid}/sync");

        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertStringNotContainsString('Tajny interni detail', $response->getContent());
        $this->assertStringNotContainsString('requisition-tajne-1', $response->getContent());
        $ulozena = (string) $connection->fresh()->last_error;
        $this->assertNotSame('', $ulozena);
        $this->assertStringNotContainsString('Tajny interni detail', $ulozena);

        $this->getJson('/api/v1/banking?gallery_space_id='.$this->prostor->id)->assertOk()
            ->assertDontSee('Tajny interni detail');
    }

    public function test_serializovane_pripojeni_skryva_identifikatory_poskytovatele(): void
    {
        $pole = $this->pripojeni()->toArray();

        foreach (['requisition_id', 'agreement_id', 'oauth_state_hash', 'encrypted_metadata', 'last_error'] as $klic) {
            $this->assertArrayNotHasKey($klic, $pole);
        }
    }

    public function test_pravidlo_s_vyslovne_prazdnymi_volbami_dostane_vychozi_hodnoty(): void
    {
        $this->postJson('/api/v1/banking/rules', ['gallery_space_id' => $this->prostor->id, 'field' => 'merchant',
            'operator' => null, 'pattern' => 'Lidl', 'category' => 'food', 'trip_action' => null, 'priority' => null])
            ->assertCreated();

        $this->assertDatabaseHas('bank_category_rules', ['pattern' => 'Lidl', 'operator' => 'contains', 'trip_action' => 'suggest', 'priority' => 100]);
    }
}
