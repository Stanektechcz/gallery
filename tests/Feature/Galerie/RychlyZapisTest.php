<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rychlý zápis z telefonu končí v deníku a na nástěnce, ne v paměti telefonu.
 */
class RychlyZapisTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian Stanek']);
        $this->maki = User::factory()->create(['name' => 'Makinka Kubíčková']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Zápis je soukromý a v deníku se objeví hned v odpovědi. */
    public function test_zapis_do_deniku_je_soukromy(): void
    {
        $this->postJson('/api/rychle/denik', ['nadpis' => 'Nad mlhou', 'text' => 'Ráno jsme vyšli nad mlhu.'])
            ->assertStatus(201)
            ->assertJsonPath('data.ADIARY.diary.0.1', 'Nad mlhou');

        $this->assertDatabaseHas('journal_entries', [
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Nad mlhou',
            'visibility' => 'private',
        ]);

        // Druhý z dvojice cizí soukromý zápis nevidí.
        Sanctum::actingAs($this->maki);
        $this->assertSame([], $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.diary') ?? []);
    }

    public function test_prazdny_zapis_neprojde(): void
    {
        $this->postJson('/api/rychle/denik', ['nadpis' => 'Jen nadpis'])->assertStatus(422);
    }

    /** Úkol s křestním jménem dostane toho člověka, „spolu" nikoho. */
    public function test_ukol_se_zalozi_a_prideli_podle_jmena(): void
    {
        $this->postJson('/api/rychle/ukol', ['nazev' => 'Odvézt kolo do servisu', 'kdo' => 'Makinka zítra'])->assertStatus(201);
        $this->postJson('/api/rychle/ukol', ['nazev' => 'Zalít zahradu', 'kdo' => 'spolu'])->assertStatus(201);

        $this->assertSame($this->maki->id, (int) DB::table('shared_todos')->where('title', 'Odvézt kolo do servisu')->value('assigned_to'));
        $this->assertNull(DB::table('shared_todos')->where('title', 'Zalít zahradu')->value('assigned_to'));
        $this->assertSame(2, DB::table('shared_todos')->where('gallery_space_id', $this->prostor->id)->where('status', 'open')->count());
    }

    /** Bez prostoru se nezapisuje nikam. */
    public function test_bez_prostoru_nic(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertContains($this->postJson('/api/rychle/ukol', ['nazev' => 'Cizí úkol'])->status(), [403, 404]);
        $this->assertSame(0, DB::table('shared_todos')->count());
    }
}
