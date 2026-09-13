<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lidé v galerii: jméno, skrytí a sloučení se ukládají.
 *
 * Dosud to všechno žilo ve stavu prohlížeče a po načtení se vrátilo staré
 * jméno i obě sloučené osoby.
 */
class LideGalerieTest extends TestCase
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

    public function test_prejmenovani_se_ulozi_a_vrati_se_v_osobach(): void
    {
        $osoba = $this->osoba('Klara');
        $this->fotkaS($osoba);

        $this->patchJson('/api/osoby/'.$osoba->id, ['jmeno' => 'Klára'])
            ->assertOk()
            ->assertJsonPath('osoba', $osoba->id)
            ->assertJsonPath('data.PERSONS.Klára.n', $osoba->id);

        $this->assertSame('Klára', $osoba->fresh()->name);
    }

    public function test_skryti_z_hledani(): void
    {
        $osoba = $this->osoba('Bára');
        $this->fotkaS($osoba);

        $this->patchJson('/api/osoby/'.$osoba->id, ['skryta' => true])
            ->assertOk()
            ->assertJsonPath('data.PERSONS.Bára.tag', 'skryto');

        $this->assertTrue($osoba->fresh()->is_hidden);
    }

    /** Sloučení převede fotky i alba a zdrojovou osobu zruší; společná fotka se nezdvojí. */
    public function test_slouceni_prevede_fotky(): void
    {
        $zdroj = $this->osoba('Mamka');
        $cil = $this->osoba('Maminka');
        $spolecna = $this->fotkaS($zdroj);
        $this->pripoj($spolecna, $cil);
        $jenZdroj = $this->fotkaS($zdroj);

        $this->postJson('/api/osoby/'.$zdroj->id.'/sloucit', ['do' => $cil->id])->assertOk();

        $this->assertNull(Person::find($zdroj->id));
        $this->assertSame(2, DB::table('media_person')->where('person_id', $cil->id)->count());
        $this->assertTrue(DB::table('media_person')->where('person_id', $cil->id)->where('media_item_id', $jenZdroj->id)->exists());
    }

    public function test_osobu_nejde_sloucit_samu_se_sebou(): void
    {
        $osoba = $this->osoba('Klára');

        $this->postJson('/api/osoby/'.$osoba->id.'/sloucit', ['do' => $osoba->id])->assertStatus(422);
        $this->assertNotNull($osoba->fresh());
    }

    /** Cizí osobu nejde přejmenovat ani do ní slučovat. */
    public function test_cizi_osoba_je_nedostupna(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziOsoba = Person::create(['gallery_space_id' => $ciziProstor->id, 'name' => 'Cizí', 'created_by' => $cizi->id]);
        $moje = $this->osoba('Klára');

        $this->patchJson('/api/osoby/'.$ciziOsoba->id, ['jmeno' => 'Ukradeno'])->assertNotFound();
        $this->postJson('/api/osoby/'.$moje->id.'/sloucit', ['do' => $ciziOsoba->id])->assertNotFound();

        $this->assertSame('Cizí', $ciziOsoba->fresh()->name);
        $this->assertNotNull($moje->fresh());
    }

    private function osoba(string $jmeno): Person
    {
        return Person::create(['gallery_space_id' => $this->prostor->id, 'name' => $jmeno, 'created_by' => $this->adri->id]);
    }

    private function fotkaS(Person $osoba): MediaItem
    {
        $m = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'taken_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ]);

        $this->pripoj($m, $osoba);

        return $m;
    }

    private function pripoj(MediaItem $m, Person $osoba): void
    {
        DB::table('media_person')->insert(['media_item_id' => $m->id, 'person_id' => $osoba->id, 'created_at' => now()]);
    }
}
