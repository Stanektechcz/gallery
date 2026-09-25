<?php

namespace Tests\Feature;

use App\Models\BillingModule;
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
        $response = $this->getJson('/api/v1/public/billing/catalogue')->assertOk();

        // Looked up by code, not by position: adding a module that sorts ahead of this one
        // would otherwise fail a test that was never about the order of the price list.
        $modules = collect($response->json('modules'))->keyBy('code');

        // By code, like the modules below it. Adding a plan that sorts ahead of duo would
        // otherwise fail a test about the catalogue being public, not about its order.
        $plans = collect($response->json('plans'))->keyBy('code');
        $this->assertArrayHasKey('duo', $plans);
        $this->assertTrue($plans['duo']['is_default'], 'Duo má být výchozí tarif.');
        $this->assertArrayHasKey('burps', $modules);
        // Prices are minor units: 49 CZK.
        $this->assertSame(4900, $modules['burps']['price_monthly']);
    }

    public function test_the_burp_module_is_locked_until_it_is_switched_on(): void
    {
        [$owner, , $space] = $this->couple();

        $this->actingAs($owner)->getJson('/api/v1/burps')->assertStatus(402);

        $module = BillingModule::where('code', 'burps')->firstOrFail();
        app(EntitlementService::class)->enableModule($space, $module, $owner);

        $this->getJson('/api/v1/burps')->assertOk()->assertJsonPath('burps', []);
    }

    /**
     * Placený modul bez platby zapne jen provozovatel.
     *
     * Dřív stačilo `users.role = owner` — a to má každý, kdo se sám
     * zaregistruje. Vlastník prostoru teď dostane 422 s odkazem na platbu.
     */
    public function test_only_the_operator_may_switch_a_paid_module_on_without_paying(): void
    {
        [$owner, $partner, $space] = $this->couple();
        config(['gallery.operator_emails' => $owner->email]);

        $this->actingAs($partner)->putJson('/api/v1/billing/modules/burps', ['enabled' => true])->assertForbidden();
        $this->actingAs($owner)->putJson('/api/v1/billing/modules/burps', ['enabled' => true])->assertOk();

        $this->assertTrue(app(EntitlementService::class)->hasModule($space->fresh(), 'burps'));

        // Turning it off closes the gate again.
        $this->putJson('/api/v1/billing/modules/burps', ['enabled' => false])->assertOk();
        $this->actingAs($partner)->getJson('/api/v1/burps')->assertStatus(402);

        // Obyčejný vlastník — tedy každý zaregistrovaný zákazník — ho musí koupit.
        config(['gallery.operator_emails' => 'provoz@jinde.test']);
        $this->actingAs($owner)->putJson('/api/v1/billing/modules/burps', ['enabled' => true])->assertStatus(422);
        $this->assertFalse(app(EntitlementService::class)->hasModule($space->fresh(), 'burps'));
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

    /**
     * Placený tarif přidělí provozovatel; vlastník prostoru si vybere jen ten
     * zdarma, placený kupuje přes platební bránu.
     */
    public function test_a_plan_can_be_assigned_and_is_reflected_in_the_overview(): void
    {
        [$owner] = $this->couple();
        $operator = User::factory()->create(['email' => 'provoz@vzpominky.test', 'role' => 'owner', 'is_active' => true]);
        config(['gallery.operator_emails' => $operator->email]);
        $space = GallerySpace::where('owner_id', $owner->id)->firstOrFail();
        $space->members()->attach($operator->id, ['role' => 'admin', 'joined_at' => now()]);

        $this->actingAs($owner)->getJson('/api/v1/billing/overview')
            ->assertOk()
            // Falls back to the plan flagged as default.
            ->assertJsonPath('plan.code', 'duo');

        // Placený tarif si zákazník nepřidělí, kupuje ho.
        $this->putJson('/api/v1/billing/plan', ['plan_code' => 'rodina'])->assertStatus(422);
        $this->getJson('/api/v1/billing/overview')->assertOk()->assertJsonPath('plan.code', 'duo');

        $this->actingAs($operator)->putJson('/api/v1/billing/plan', ['plan_code' => 'rodina'])->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/billing/overview')->assertOk()->assertJsonPath('plan.code', 'rodina');

        // Návrat na tarif zdarma zvládne vlastník sám.
        $this->putJson('/api/v1/billing/plan', ['plan_code' => 'duo'])->assertOk();
        $this->getJson('/api/v1/billing/overview')->assertOk()->assertJsonPath('plan.code', 'duo');
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
