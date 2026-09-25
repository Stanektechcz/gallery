<?php

namespace Tests\Feature\Dodelky;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vypršení dokladů a dálničních známek se počítá podle pásma dvojice
 * (Europe/Prague), ne podle UTC data serveru.
 */
class CestovniDokumentyExpiraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_expired_yesterday_in_prague_is_reported_expired_even_before_utc_midnight(): void
    {
        $this->travelTo('2026-09-30 22:30:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Cesta', 'slug' => 'cesta', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $space->id, 'created_by' => $owner->id, 'name' => 'Výlet',
            'start_date' => '2026-09-25', 'end_date' => '2026-10-05', 'status' => 'planned', 'timezone' => 'Europe/Prague', 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_document_checks')->insert(['trip_id' => $tripId, 'created_by' => $owner->id, 'type' => 'passport', 'title' => 'Pas',
            'expires_on' => '2026-09-30', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($owner)->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk();
        // 22:30 UTC je 0:30 v Praze — už 1. října, takže doklad platný do 30. 9. je vypršelý.
        $this->assertCount(1, $response->json('expired_documents'));
    }
}
