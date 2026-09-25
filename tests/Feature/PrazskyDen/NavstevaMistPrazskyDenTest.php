<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Datum návštěvy bez zadané hodnoty patřilo k UTC dni serveru, ne k
 * pražskému dni dvojice — po půlnoci se tak zapsalo o den dřív.
 */
class NavstevaMistPrazskyDenTest extends TestCase
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

    public function test_navsteva_mista_na_seznamu_se_zapise_k_prazskemu_dni(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        $id = DB::table('itinerary_places')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Alpy',
            'visited' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/itinerary/{$id}", ['visited' => true])
            ->assertOk()
            ->assertJson(['visited_at' => '2026-09-25']);
    }

    public function test_plan_navstevy_mista_se_uzavre_k_prazskemu_dni(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        $place = Place::create(['gallery_space_id' => $this->space->id, 'name' => 'Kavárna U Stromu']);
        $uuid = (string) Str::uuid();
        DB::table('place_plans')->insert([
            'uuid' => $uuid, 'place_id' => $place->id, 'gallery_space_id' => $this->space->id,
            'created_by' => $this->owner->id, 'state' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/places/{$place->id}/plans/{$uuid}", ['state' => 'visited'])
            ->assertOk()
            ->assertJson(['visited_on' => '2026-09-25']);
    }
}
