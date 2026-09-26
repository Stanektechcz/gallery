<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SchemaMimoTransakce;
use Tests\TestCase;

/**
 * Plánování přežije instalaci, na které volitelná migrace ještě neproběhla.
 *
 * Dřív byl tenhle test v `PlanningExpansionTest` pod `RefreshDatabase`.
 * Tabulky se tu opravdu zahazují — jen tak se pozná dotaz, který na ně sahá
 * bez `Schema::hasTable()` — a na MySQL zahození potvrdí transakci testu:
 * sedm tabulek pak chybělo i dalším testům a vytvoření události spadlo na
 * SAVEPOINT mimo transakci. Proto vlastní třída se schématem mimo transakci.
 */
class PlanningBezVolitelnychTabulekTest extends TestCase
{
    use SchemaMimoTransakce;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'owner']);
        $partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Plán', 'slug' => 'plan', 'owner_id' => $owner->id]);
        $this->space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($owner);
    }

    public function test_planning_screen_dependencies_degrade_safely_when_an_optional_migration_is_not_yet_present(): void
    {
        // Od listů ke kořenům: MySQL nezahodí tabulku, na kterou ještě míří cizí klíč.
        Schema::dropIfExists('event_templates');
        Schema::dropIfExists('travel_wishlist_items');
        Schema::dropIfExists('travel_wishlists');
        Schema::dropIfExists('decision_poll_votes');
        Schema::dropIfExists('decision_poll_options');
        Schema::dropIfExists('decision_polls');

        $this->getJson('/api/v1/calendar/templates')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/calendar/wishlists')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/calendar/polls')->assertOk()->assertExactJson([]);
        $this->postJson('/api/v1/calendar/templates', ['gallery_space_id' => $this->space->id, 'title' => 'Víkend'])->assertStatus(503);

        Schema::dropIfExists('calendar_event_exceptions');
        $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Bez výjimek', 'starts_at' => now()->addWeek()->toDateTimeString(), 'recurrence_rule' => ['frequency' => 'weekly']])->assertCreated();
        $this->getJson('/api/v1/calendar/events')->assertOk();
    }
}
