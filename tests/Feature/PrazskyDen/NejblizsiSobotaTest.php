<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `startOfWeek()->next(SATURDAY)` v neděli přeskočí dnešní sobotu úplně a
 * vrátí tu z minulého týdne — o den dřív, než dvojice čeká.
 */
class NejblizsiSobotaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase-'.Str::random(6), 'owner_id' => $this->owner->id, 'is_default' => true]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    private function wishlist(): string
    {
        return $this->postJson('/api/v1/calendar/wishlists', ['gallery_space_id' => $this->space->id, 'title' => 'Sny'])
            ->assertCreated()->json('uuid');
    }

    public function test_volne_vikendy_v_nedeli_zacinaji_nejblizsi_sobotou(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00', 'Europe/Prague'));

        $uuid = $this->wishlist();

        $response = $this->getJson("/api/v1/calendar/wishlists/{$uuid}/suggestions")->assertOk();

        $this->assertSame('2026-10-03', $response->json('free_weekends.0.date'));
    }

    public function test_planovani_bez_data_vybere_dnesni_sobotu_pred_desatou(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00', 'Europe/Prague'));

        $uuid = $this->wishlist();
        $item = $this->postJson("/api/v1/calendar/wishlists/{$uuid}/items", ['title' => 'Hory'])->assertCreated()->json();
        DB::table('travel_wishlist_items')->where('id', $item['id'])->update(['calendar_event_id' => null]);

        $event = $this->postJson("/api/v1/calendar/wishlists/{$uuid}/items/{$item['id']}/plan", [])->assertCreated()->json();

        $this->assertStringStartsWith('2026-10-03', $event['starts_at']);
    }

    public function test_planovani_bez_data_po_desate_preskoci_dnesni_sobotu(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 11:00', 'Europe/Prague'));

        $uuid = $this->wishlist();
        $item = $this->postJson("/api/v1/calendar/wishlists/{$uuid}/items", ['title' => 'Hory'])->assertCreated()->json();

        $event = $this->postJson("/api/v1/calendar/wishlists/{$uuid}/items/{$item['id']}/plan", [])->assertCreated()->json();

        $this->assertStringStartsWith('2026-10-10', $event['starts_at']);
    }
}
