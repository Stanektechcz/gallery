<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Výdaj cesty a bod programu z galerie končí v tabulkách cesty.
 */
class CestyAkceTest extends TestCase
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

    public function test_vydaj_se_zapise_k_ceste(): void
    {
        $cesta = $this->cesta();

        $this->postJson('/api/cesty/'.$cesta.'/vydaj', ['nazev' => 'Trajekt', 'castka' => 640, 'kategorie' => 'Doprava'])->assertStatus(201);

        $vydaj = DB::table('trip_expenses')->sole();
        $this->assertSame('transport', $vydaj->category);
        $this->assertSame('actual', $vydaj->state);
        $this->assertEquals(640, (float) $vydaj->amount);
    }

    public function test_bod_programu_zalozi_den_a_aktivitu(): void
    {
        $cesta = $this->cesta();

        $this->postJson('/api/cesty/'.$cesta.'/program', ['den' => 1, 'nazev' => 'Krka', 'cas' => '9:30', 'misto' => 'NP Krka'])
            ->assertStatus(201);

        $den = DB::table('trip_days')->sole();
        $this->assertSame(now()->addWeek()->addDay()->toDateString(), substr((string) $den->date, 0, 10));
        $this->assertSame('Krka', DB::table('trip_activities')->value('title'));

        // Den za koncem cesty neexistuje.
        $this->postJson('/api/cesty/'.$cesta.'/program', ['den' => 30, 'nazev' => 'Mimo'])->assertStatus(422);
    }

    /**
     * Dny cesty jdou po sobě, ne pozpátku.
     *
     * `sort_order` se počítal jako `$datum->diffInDays($start)`, jenže Carbon 3
     * vrací rozdíl **se znaménkem** a v tomhle pořadí je to `start − datum` —
     * tedy záporné číslo pro každý den po tom prvním. Sloupec je
     * `unsignedSmallInteger`, takže na MySQL ve striktním režimu celá
     * transakce spadne; na SQLite se itinerář jen seřadí obráceně.
     */
    public function test_dny_cesty_maji_rostouci_poradi(): void
    {
        $cesta = $this->cesta();

        foreach ([1, 3, 5] as $den) {
            $this->postJson('/api/cesty/'.$cesta.'/program', ['den' => $den, 'nazev' => 'Den '.$den])
                ->assertStatus(201);
        }

        $poradi = DB::table('trip_days')->orderBy('date')->pluck('sort_order')->map('intval')->all();

        // `den => N` je N. den po začátku cesty (viz test výš), takže pořadí
        // je rovnou N. Podstatné je, že roste a není záporné.
        $this->assertSame([1, 3, 5], $poradi,
            'Pořadí dne se počítá od začátku cesty a roste — záporné číslo se do sloupce ani nevejde.');
    }

    public function test_posunuti_bodu_programu(): void
    {
        $cesta = $this->cesta();
        $this->postJson('/api/cesty/'.$cesta.'/program', ['den' => 0, 'nazev' => 'Snídaně', 'cas' => '8:00'])->assertStatus(201);
        $id = (int) DB::table('trip_activities')->value('id');

        $this->postJson('/api/cesty/program/'.$id.'/posunout', ['minut' => 60])->assertOk();
        $this->assertSame('09:00:00', substr((string) DB::table('trip_activities')->value('starts_at'), 0, 8));

        // 9:00 + 12 h = 21:00, dalších 12 h by přeteklo přes půlnoc.
        $this->postJson('/api/cesty/program/'.$id.'/posunout', ['minut' => 720])->assertOk();
        $this->postJson('/api/cesty/program/'.$id.'/posunout', ['minut' => 720])->assertStatus(422);
    }

    /**
     * Přes půlnoc nesmí ani konec bodu.
     *
     * Hlídal se jen začátek: 22:30–23:45 posunuté o hodinu dalo 23:30–00:45,
     * tedy konec dřív než začátek — v itineráři bod „skončil" ráno téhož dne.
     */
    public function test_posunuti_nepreleje_konec_pres_pulnoc(): void
    {
        $cesta = $this->cesta();
        $this->postJson('/api/cesty/'.$cesta.'/program', ['den' => 0, 'nazev' => 'Noční výhled', 'cas' => '22:30'])->assertStatus(201);
        $id = (int) DB::table('trip_activities')->value('id');
        DB::table('trip_activities')->where('id', $id)->update(['ends_at' => '23:45:00']);

        $this->postJson('/api/cesty/program/'.$id.'/posunout', ['minut' => 60])
            ->assertStatus(422)
            ->assertJsonPath('zprava', 'Posunout přes půlnoc nejde — přesuňte bod do jiného dne.');

        $radek = DB::table('trip_activities')->where('id', $id)->first();
        $this->assertSame('22:30:00', substr((string) $radek->starts_at, 0, 8));
        $this->assertSame('23:45:00', substr((string) $radek->ends_at, 0, 8));

        // Uvnitř dne to jde dál.
        $this->postJson('/api/cesty/program/'.$id.'/posunout', ['minut' => 10])->assertOk();
        $this->assertSame('23:55:00', substr((string) DB::table('trip_activities')->where('id', $id)->value('ends_at'), 0, 8));
    }

    public function test_cizi_cesta_je_nedostupna(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $cesta = DB::table('trips')->insertGetId([
            'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id, 'name' => 'Cizí',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/cesty/'.$cesta.'/vydaj', ['nazev' => 'x', 'castka' => 1])->assertNotFound();
        $this->assertSame(0, DB::table('trip_expenses')->count());
    }

    private function cesta(): int
    {
        return DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id, 'name' => 'Chorvatsko',
            'description' => '', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeeks(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
