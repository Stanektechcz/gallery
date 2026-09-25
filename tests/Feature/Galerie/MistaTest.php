<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Místa z galerie: nový cíl, „byli jsme" a společná poznámka.
 */
class MistaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_novy_cil_je_v_seznamu_prani(): void
    {
        $this->postJson('/api/mista', ['nazev' => 'Lofoty', 'zeme' => 'Norsko', 'poznamka' => 'v červnu'])
            ->assertStatus(201)
            ->assertJsonPath('data.SVET.chceme.0.0', 'Lofoty');

        $this->assertSame('shared', DB::table('place_notes')->value('scope_key'));
        $this->postJson('/api/mista', ['nazev' => 'lofoty'])->assertStatus(422);
    }

    /**
     * `mesto`/`zeme` delší než sloupce `places.city`/`places.country` (100 znaků)
     * se odmítne s 422.
     *
     * Sloupce jsou `string(100)`, validace dřív pouštěla `max:120` — na
     * SQLite v testech to prošlo potichu, na MySQL ve striktním režimu by
     * to spadlo na 500 místo srozumitelné chyby.
     */
    public function test_prilis_dlouhe_mesto_a_zeme_dostanou_422(): void
    {
        $this->postJson('/api/mista', [
            'nazev' => 'Lofoty',
            'mesto' => str_repeat('x', 101),
        ])->assertStatus(422)->assertJsonValidationErrors('mesto');

        $this->postJson('/api/mista', [
            'nazev' => 'Lofoty',
            'zeme' => str_repeat('x', 101),
        ])->assertStatus(422)->assertJsonValidationErrors('zeme');
    }

    public function test_byli_jsme_zmeni_stav_mista(): void
    {
        $misto = Place::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Island', 'lifecycle_status' => 'idea']);

        $this->postJson('/api/mista/stav', ['nazev' => 'Island', 'navstiveno' => true])->assertOk();
        $this->assertSame('visited', $misto->fresh()->lifecycle_status);

        $this->postJson('/api/mista/stav', ['nazev' => 'Cesta, která není místo', 'navstiveno' => true])->assertStatus(422);
    }

    public function test_poznamka_se_ulozi_prepise_a_smaze(): void
    {
        Place::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Pustevny']);

        $this->postJson('/api/mista/poznamka', ['nazev' => 'Pustevny', 'text' => 'Parkovat dole'])
            ->assertOk()
            ->assertJsonPath('data.PLACES.pustevny.notes.0.2', 'Parkovat dole');

        $this->postJson('/api/mista/poznamka', ['nazev' => 'Pustevny', 'text' => 'Parkovat nahoře'])->assertOk();
        $this->assertSame(1, DB::table('place_notes')->count());

        $this->postJson('/api/mista/poznamka', ['nazev' => 'Pustevny', 'text' => ''])->assertOk();
        $this->assertSame(0, DB::table('place_notes')->count());
    }

    public function test_cizi_misto_nejde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $misto = Place::create(['gallery_space_id' => $ciziProstor->id, 'name' => 'Tajné', 'lifecycle_status' => 'idea']);

        $this->postJson('/api/mista/stav', ['nazev' => 'Tajné', 'navstiveno' => true])->assertStatus(422);
        $this->postJson('/api/mista/poznamka', ['nazev' => 'Tajné', 'text' => 'x'])->assertNotFound();
        $this->assertSame('idea', $misto->fresh()->lifecycle_status);
    }
}
