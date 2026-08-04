<?php

namespace Tests\Feature;

use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SaasModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCatalogSeeder::class);
    }

    public function test_the_public_pricing_catalogue_needs_no_account(): void
    {
        $this->getJson('/api/v1/public/billing/catalogue')
            ->assertOk()
            ->assertJsonPath('plans.0.code', 'duo')
            ->assertJsonPath('modules.0.code', 'burps')
            // Prices are minor units: 49 CZK.
            ->assertJsonPath('modules.0.price_monthly', 4900);
    }

    public function test_the_burp_module_is_locked_until_it_is_switched_on(): void
    {
        [$owner, , $space] = $this->couple();

        $this->actingAs($owner)->getJson('/api/v1/burps')->assertStatus(402);

        $module = BillingModule::where('code', 'burps')->firstOrFail();
        app(EntitlementService::class)->enableModule($space, $module, $owner);

        $this->getJson('/api/v1/burps')->assertOk()->assertJsonPath('burps', []);
    }

    public function test_only_an_administrator_may_switch_a_module_on(): void
    {
        [$owner, $partner, $space] = $this->couple();

        $this->actingAs($partner)->putJson('/api/v1/billing/modules/burps', ['enabled' => true])->assertForbidden();
        $this->actingAs($owner)->putJson('/api/v1/billing/modules/burps', ['enabled' => true])->assertOk();

        $this->assertTrue(app(EntitlementService::class)->hasModule($space->fresh(), 'burps'));

        // Turning it off closes the gate again.
        $this->putJson('/api/v1/billing/modules/burps', ['enabled' => false])->assertOk();
        $this->actingAs($partner)->getJson('/api/v1/burps')->assertStatus(402);
    }

    public function test_voice_notes_are_included_in_every_plan(): void
    {
        [$owner, , $space] = $this->couple();
        $this->assertTrue(app(EntitlementService::class)->hasModule($space, EntitlementService::MODULE_VOICE_NOTES));

        Storage::fake('local');
        $this->actingAs($owner)->postJson('/api/v1/voice-notes', [
            'audio' => UploadedFile::fake()->create('vzkaz.webm', 40, 'audio/webm'),
            'title' => 'Dobré ráno',
            'duration_ms' => 4200,
        ])->assertCreated()->assertJsonPath('title', 'Dobré ráno');

        $this->getJson('/api/v1/voice-notes')->assertOk()->assertJsonCount(1, 'notes');
    }

    public function test_a_voice_note_is_not_served_to_someone_outside_the_space(): void
    {
        [$owner] = $this->couple();
        $stranger = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        Storage::fake('local');

        $note = $this->actingAs($owner)->postJson('/api/v1/voice-notes', [
            'audio' => UploadedFile::fake()->create('vzkaz.webm', 20, 'audio/webm'),
        ])->assertCreated()->json();

        $this->actingAs($stranger)->get("/api/v1/voice-notes/{$note['uuid']}/stream")->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/v1/voice-notes/{$note['uuid']}")->assertNotFound();
    }

    public function test_a_burp_is_scored_by_the_partner_and_never_by_its_author(): void
    {
        [$owner, $partner, $space] = $this->couple();
        app(EntitlementService::class)->enableModule($space, BillingModule::where('code', 'burps')->firstOrFail(), $owner);
        Storage::fake('local');

        $burp = $this->actingAs($owner)->postJson('/api/v1/burps', [
            'title' => 'Nedělní klasika', 'occasion' => 'po obědě', 'duration_ms' => 1800,
        ])->assertCreated()->json();

        // Rating your own is refused.
        $this->putJson("/api/v1/burps/{$burp['uuid']}/rating", [
            'loudness' => 5, 'length' => 5, 'artistry' => 5, 'surprise' => 5,
        ])->assertStatus(422);

        $this->actingAs($partner)->putJson("/api/v1/burps/{$burp['uuid']}/rating", [
            'loudness' => 5, 'length' => 4, 'artistry' => 3, 'surprise' => 4, 'comment' => 'Slušné.',
        ])->assertOk();
        // JSON turns 4.00 into 4, so compare by value rather than by type.
        $this->assertEquals(4, $this->getJson('/api/v1/burps')->json('burps.0.average_score'));

        $this->assertDatabaseHas('burp_ratings', ['user_id' => $partner->id, 'score' => 4.0, 'comment' => 'Slušné.']);

        // Leaderboard and champion follow from the ratings.
        $index = $this->getJson('/api/v1/burps')->assertOk()->json();
        $this->assertSame($owner->id, $index['leaderboard'][0]['user']['id']);
        $this->assertEquals(4, $index['champion']['score']);
    }

    public function test_a_plan_can_be_assigned_and_is_reflected_in_the_overview(): void
    {
        [$owner] = $this->couple();

        $this->actingAs($owner)->getJson('/api/v1/billing/overview')
            ->assertOk()
            // Falls back to the plan flagged as default.
            ->assertJsonPath('plan.code', 'duo');

        $this->putJson('/api/v1/billing/plan', ['plan_code' => 'rodina'])->assertOk();
        $this->getJson('/api/v1/billing/overview')->assertOk()->assertJsonPath('plan.code', 'rodina');
    }

    /** @return array{0:User,1:User,2:GallerySpace} */
    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return [$owner, $partner, $space];
    }
}
