<?php

namespace Tests\Feature\Dodelky;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Přehled financí nesmí ukázat syrovou chybu bankovního API — ta v `last_error`
 * mohla zůstat u připojení uloženého ještě před opravou `BankingIntegrationService`.
 */
class BankPripojeniChybaTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_provider_error_never_reaches_the_response(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Finance', 'slug' => 'finance', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $rawError = 'cURL error 28: https://bankaccountdata.gocardless.com/api/v2/requisitions/abc?secret=topsecret';
        DB::table('bank_connections')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'connected_by' => $owner->id,
            'provider' => 'gocardless', 'institution_name' => 'Revolut', 'status' => 'failed', 'sync_enabled' => true,
            'last_error' => $rawError, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v1/banking?gallery_space_id='.$space->id)->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString('gocardless.com', $body);
        $this->assertStringNotContainsString('topsecret', $body);
        $this->assertTrue((bool) $response->json('connections.0.has_error'));
    }
}
