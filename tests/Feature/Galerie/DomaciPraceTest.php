<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\HouseChore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Domácí práce jde založit a odebrat.
 *
 * Dělba vznikala jen „prvním dotekem" ukázky; dvojice s prázdnou domácností
 * ji neměla jak začít.
 */
class DomaciPraceTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    public function test_nova_prace_se_zapise_a_vrati_v_delbe(): void
    {
        $odpoved = $this->postJson('/api/domacnost/prace', [
            'nazev' => 'Vynést tříděný odpad', 'jak_casto' => '2× týdně', 'minuty' => 10,
        ])->assertStatus(201)->assertJsonPath('ok', true);

        $prace = HouseChore::where('gallery_space_id', $this->prostor->id)->sole();
        $this->assertSame('2× týdně', $prace->every);
        $this->assertSame(10, (int) $prace->minutes);
        $this->assertSame($this->adri->id, $prace->assigned_to);
        $this->assertTrue($prace->rotate);
        $this->assertSame('ph-trash', $prace->icon);

        $radek = $odpoved->json('data.HOUSE_CHORES.0');
        $this->assertSame('Vynést tříděný odpad', $radek['name']);
        $this->assertSame('Adrian', $radek['who']);
        $this->assertSame('zatím nikdy', $radek['last']);
    }

    public function test_prace_pro_druheho_a_spolecna(): void
    {
        $this->postJson('/api/domacnost/prace', ['nazev' => 'Vysát', 'kdo' => 'druhy'])->assertStatus(201);
        $this->postJson('/api/domacnost/prace', ['nazev' => 'Velký nákup', 'kdo' => 'spolu'])->assertStatus(201);

        $this->assertSame($this->maki->id, HouseChore::where('name', 'Vysát')->value('assigned_to'));

        $spolu = HouseChore::where('name', 'Velký nákup')->sole();
        $this->assertNull($spolu->assigned_to);
        $this->assertFalse($spolu->rotate, 'Společná práce nemá komu se střídat.');
        $this->assertSame('týdně', $spolu->every);
    }

    public function test_stejna_prace_dvakrat_ani_nesmysl_neprojdou(): void
    {
        $this->postJson('/api/domacnost/prace', ['nazev' => 'Vysát'])->assertStatus(201);

        $this->postJson('/api/domacnost/prace', ['nazev' => 'vysát'])
            ->assertStatus(422)->assertJsonPath('zprava', 'Práci „vysát“ už v dělbě máte.');
        $this->postJson('/api/domacnost/prace', ['nazev' => 'Okna', 'jak_casto' => 'občas'])->assertStatus(422);
        $this->postJson('/api/domacnost/prace', ['nazev' => 'Okna', 'minuty' => 2000])->assertStatus(422);

        $this->assertSame(1, HouseChore::count());
    }

    /** Odebraná práce zmizí z dělby, historie zůstane a stará kopie ji nevzkřísí. */
    public function test_odebrana_prace_se_nevrati_ze_stare_kopie(): void
    {
        $this->postJson('/api/domacnost/prace', ['nazev' => 'Vysát'])->assertStatus(201);
        $prace = HouseChore::sole();
        DB::table('house_chore_log')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'user_id' => $this->adri->id,
            'house_chore_id' => $prace->id, 'chore_name' => 'Vysát', 'minutes' => 30, 'done_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson('/api/domacnost/prace/'.$prace->uuid)
            ->assertOk()
            ->assertJsonPath('data.HOUSE_CHORES', fn ($p) => $p === null || $p === []);

        $this->assertSame(0, HouseChore::count());
        $this->assertSame(1, DB::table('house_chore_log')->count());

        // Druhý měl otevřenou obrazovku se starým seznamem a odškrtl jinou věc.
        $this->patchJson('/api/state', ['data' => ['chores' => [[
            'id' => $prace->uuid, 'name' => 'Vysát', 'who' => 'Adrian', 'rotate' => true, 'mins' => 30,
        ]]]])->assertOk();

        $this->assertSame(0, HouseChore::count());
    }

    public function test_cizi_prace_nejde_odebrat(): void
    {
        $jina = User::factory()->create();
        $cizi = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $jina->id]);
        $jejich = HouseChore::create(['gallery_space_id' => $cizi->id, 'name' => 'Jejich práce']);

        $this->deleteJson('/api/domacnost/prace/'.$jejich->uuid)->assertNotFound();
        $this->assertSame(1, HouseChore::count());
    }
}
