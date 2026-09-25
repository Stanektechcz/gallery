<?php

namespace Tests\Feature\RozsahTimestampu;

use App\Models\BillingModule;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `burps.happened_at` a `farts.happened_at` jsou sloupce MySQL `TIMESTAMP`
 * (rozsah 1970-01-01 00:00:01 až 2038-01-19 03:14:07 UTC). SQLite v testech
 * přijme cokoli, ale na produkční MySQL by mimo rozsah zápis spadl na 500
 * místo srozumitelného 422 — proto validace přes `RozsahTimestamp`.
 */
class BurpFartTimestampTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCatalogSeeder::class);

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $this->owner->id, 'is_default' => true]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        $this->actingAs($this->owner);
    }

    private function povolModul(string $kod): void
    {
        $modul = BillingModule::where('code', $kod)->firstOrFail();
        app(EntitlementService::class)->enableModule($this->space, $modul, $this->owner);
    }

    public function test_krkanec_s_datem_pred_rokem_1970_dostane_422(): void
    {
        $this->povolModul('burps');

        $this->postJson('/api/v1/burps', [
            'title' => 'Starý krkanec',
            'happened_at' => '1965-06-01',
        ])->assertStatus(422)->assertJsonValidationErrors('happened_at');
    }

    public function test_krkanec_s_datem_v_rozsahu_projde(): void
    {
        $this->povolModul('burps');

        $this->postJson('/api/v1/burps', [
            'title' => 'Nedělní klasika',
            'happened_at' => '2026-09-20',
        ])->assertCreated();
    }

    public function test_ulovek_s_datem_pred_rokem_1970_dostane_422(): void
    {
        $this->povolModul('farts');

        $this->postJson('/api/v1/farts', [
            'title' => 'Starý úlovek',
            'happened_at' => '1965-06-01',
        ])->assertStatus(422)->assertJsonValidationErrors('happened_at');
    }

    public function test_ulovek_s_datem_v_rozsahu_projde(): void
    {
        $this->povolModul('farts');

        $this->postJson('/api/v1/farts', [
            'title' => 'Čerstvý úlovek',
            'happened_at' => '2026-09-20',
        ])->assertCreated();
    }
}
