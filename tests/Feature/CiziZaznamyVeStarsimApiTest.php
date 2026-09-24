<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Starší API nepřipojí k vlastním fotkám nic z cizí galerie.
 *
 * Pravidlo `exists:people,id` se ptá celé tabulky, ne galerie. Kdo znal číslo
 * cizí osoby, štítku nebo místa — a čísla jdou po sobě —, připojil si je
 * k vlastní fotce, a odpověď na úpravu mu je vrátila celé: jméno, přezdívku,
 * datum narození, adresu i souřadnice cizího místa.
 *
 * Globální rozsah prostoru tu nepomůže: `Tag`, `Person` a `Place` ho nemají
 * a `exists:` je holý dotaz, který by ho obešel tak jako tak.
 */
class CiziZaznamyVeStarsimApiTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private GallerySpace $cizi;

    private MediaItem $fotka;

    protected function setUp(): void
    {
        parent::setUp();

        // `users.role` čte staré rozhraní (AlbumPolicy); galerie má role v členství.
        $this->adri = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $soused = User::factory()->create(['name' => 'Soused']);
        $this->cizi = GallerySpace::create(['name' => 'Cizí galerie', 'owner_id' => $soused->id]);
        $this->cizi->members()->syncWithoutDetaching([$soused->id => ['role' => 'owner']]);

        $this->fotka = $this->fotka($this->prostor);

        Sanctum::actingAs($this->adri);
    }

    public function test_k_fotce_nejde_pripojit_cizi_osobu_ani_stitek(): void
    {
        $osoba = $this->osoba($this->cizi, 'Cizí Osoba');
        $stitek = $this->stitek($this->cizi, 'cizi-stitek');

        $odpoved = $this->patchJson('/api/v1/media/'.$this->fotka->uuid, [
            'person_ids' => [$osoba],
            'tag_ids' => [$stitek],
        ]);

        $odpoved->assertStatus(422);
        $this->assertStringNotContainsString('Cizí Osoba', $odpoved->getContent());
        $this->assertFalse(DB::table('media_person')->where('person_id', $osoba)->exists(),
            'Cizí osoba se připojila k naší fotce.');
        $this->assertFalse(DB::table('media_tag')->where('tag_id', $stitek)->exists());
    }

    public function test_vlastni_osoba_a_stitek_jdou_pripojit_dal(): void
    {
        $osoba = $this->osoba($this->prostor, 'Babička');
        $stitek = $this->stitek($this->prostor, 'vylet');

        $this->patchJson('/api/v1/media/'.$this->fotka->uuid, [
            'person_ids' => [$osoba],
            'tag_ids' => [$stitek],
        ])->assertOk()->assertJsonPath('people.0.name', 'Babička');

        $this->assertTrue(DB::table('media_tag')->where('tag_id', $stitek)->exists());
    }

    public function test_hromadne_nejde_pripojit_cizi_stitek_osobu_ani_misto(): void
    {
        $osoba = $this->osoba($this->cizi, 'Cizí Osoba');
        $stitek = $this->stitek($this->cizi, 'cizi-stitek');
        $misto = $this->misto($this->cizi->id, 'Cizí chata');

        foreach ([['tag', 'tag_id', $stitek], ['add_person', 'person_id', $osoba], ['add_place', 'place_id', $misto]] as [$akce, $pole, $id]) {
            $this->postJson('/api/v1/media/bulk', ['action' => $akce, 'uuids' => [$this->fotka->uuid], $pole => $id]);
        }

        $this->assertFalse(DB::table('media_tag')->where('media_item_id', $this->fotka->id)->exists(), 'Cizí štítek se připojil.');
        $this->assertFalse(DB::table('media_person')->where('media_item_id', $this->fotka->id)->exists(), 'Cizí osoba se připojila.');
        $this->assertFalse(DB::table('media_place')->where('media_item_id', $this->fotka->id)->exists(), 'Cizí místo se připojilo.');
    }

    public function test_hromadne_vlastni_stitek_projde(): void
    {
        $stitek = $this->stitek($this->prostor, 'vylet');

        $this->postJson('/api/v1/media/bulk', ['action' => 'tag', 'uuids' => [$this->fotka->uuid], 'tag_id' => $stitek])
            ->assertOk();

        $this->assertTrue(DB::table('media_tag')->where('media_item_id', $this->fotka->id)->where('tag_id', $stitek)->exists());
    }

    /**
     * Místo bez galerie neotevře nikdo cizí.
     *
     * `authorizePlace()` kontrolovalo jen tehdy, když galerie vyplněná **byla**.
     * Místa z doby před sloupcem `gallery_space_id` (a místa po smazané galerii)
     * ho mají prázdné — a ta šla číst, přepsat i smazat odkudkoli. Seznam míst
     * je nikomu neukazuje, takže přísná kontrola legitimnímu uživateli nic nebere.
     */
    public function test_misto_bez_galerie_neotevre_nikdo_cizi(): void
    {
        $sirotek = $this->misto(null, 'Stará chata');

        $this->getJson('/api/v1/places/'.$sirotek)->assertForbidden();
        $this->patchJson('/api/v1/places/'.$sirotek, ['name' => 'Přepsáno'])->assertForbidden();
        $this->deleteJson('/api/v1/places/'.$sirotek)->assertForbidden();

        $this->assertSame('Stará chata', DB::table('places')->where('id', $sirotek)->value('name'));
    }

    public function test_vlastni_misto_se_otevre(): void
    {
        $misto = $this->misto($this->prostor->id, 'Naše chata');

        $this->getJson('/api/v1/places/'.$misto)->assertOk();
    }

    /**
     * Obal alba jen z vlastní galerie.
     *
     * Obal se čte přes vztah s globálním rozsahem, takže přihlášený člověk cizí
     * fotku neuvidí. Veřejný sdílený odkaz ale běží bez přihlášení, rozsah tam
     * ustupuje — a cizí fotka by se ukázala komukoli s odkazem.
     */
    public function test_cizi_fotka_nejde_dat_jako_obal_alba(): void
    {
        $album = Album::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Léto',
            'slug' => 'leto',
            'visibility' => 'shared',
            'created_by' => $this->adri->id,
            'updated_by' => $this->adri->id,
        ]);
        $ciziFotka = $this->fotka($this->cizi);

        // Webová cesta starého rozhraní: chybu vrací přesměrováním zpět,
        // jak ji čte Inertia (JSON tu vrací jen cesty `api/*`).
        $this->actingAs($this->adri)
            ->from('/albums/'.$album->uuid)
            ->patch('/albums/'.$album->uuid, ['cover_media_id' => $ciziFotka->id])
            ->assertSessionHasErrors('cover_media_id');

        $this->assertNull(DB::table('albums')->where('id', $album->id)->value('cover_media_id'));
    }

    public function test_vlastni_fotka_jde_dat_jako_obal_alba(): void
    {
        $album = Album::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Léto',
            'slug' => 'leto',
            'visibility' => 'shared',
            'created_by' => $this->adri->id,
            'updated_by' => $this->adri->id,
        ]);

        $this->actingAs($this->adri)
            ->from('/albums/'.$album->uuid)
            ->patch('/albums/'.$album->uuid, ['cover_media_id' => $this->fotka->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->fotka->id, (int) DB::table('albums')->where('id', $album->id)->value('cover_media_id'));
    }

    // ——— pomocné ———

    private function fotka(GallerySpace $prostor): MediaItem
    {
        return MediaItem::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'owner_user_id' => $prostor->owner_id,
            'uploaded_by' => $prostor->owner_id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
        ]);
    }

    private function osoba(GallerySpace $prostor, string $jmeno): int
    {
        return DB::table('people')->insertGetId([
            'gallery_space_id' => $prostor->id,
            'name' => $jmeno,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stitek(GallerySpace $prostor, string $slug): int
    {
        return DB::table('tags')->insertGetId([
            'gallery_space_id' => $prostor->id,
            'name' => $slug,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function misto(?int $prostor, string $nazev): int
    {
        return DB::table('places')->insertGetId([
            'gallery_space_id' => $prostor,
            'name' => $nazev,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
