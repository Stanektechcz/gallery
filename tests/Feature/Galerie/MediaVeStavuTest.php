<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Srdíčko, popisek, místo, datum a štítky z prototypu jdou do databáze.
 *
 * Zapisovaly se jen do `favs` a `edits` ve stavu: na obrazovce uložené,
 * v knihovně, hledání, mapě a na Disku beze změny.
 */
class MediaVeStavuTest extends TestCase
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
    }

    public function test_srdicko_se_zapise_k_cloveku_a_zase_zrusi(): void
    {
        $foto = $this->fotka();

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => [$foto->uuid => true]]])->assertOk();
        $this->assertTrue(DB::table('user_favorites')->where('user_id', $this->adri->id)->where('media_item_id', $foto->id)->exists());

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => [$foto->uuid => false]]])->assertOk();
        $this->assertFalse(DB::table('user_favorites')->where('media_item_id', $foto->id)->exists());
    }

    public function test_popisek_misto_datum_a_stitky(): void
    {
        $foto = $this->fotka();

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => [
            'caption' => 'Ráno nad mlhou',
            'place' => 'Pustevny',
            'dateVal' => '2024-08-16',
            'timeVal' => '6:12',
            'tags' => ['hory', '#Ráno'],
        ]]]])->assertOk();

        $foto->refresh();
        $this->assertSame('Ráno nad mlhou', $foto->caption);
        $this->assertSame('Pustevny', $foto->location_name);
        $this->assertSame('2024-08-16 06:12', $foto->taken_at->format('Y-m-d H:i'));
        $this->assertEqualsCanonicalizing(['hory', 'Ráno'], $foto->tags()->pluck('name')->all());
        // Úpravy zůstávají i ve stavu — prototyp z nich kreslí hned.
        $this->assertSame('Pustevny', $this->actingAs($this->adri)->getJson('/api/state')->json('data.edits.'.$foto->uuid.'.place'));
    }

    /**
     * Kdo je na fotce: známé jméno se použije, nové založí osobu.
     *
     * Označit osobu šlo jen ve starém rozhraní — Lidé v galerii zůstávali
     * prázdní bez možnosti je naplnit.
     */
    public function test_osoby_na_fotce(): void
    {
        $foto = $this->fotka();
        $makinka = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Makinka']);
        $skryta = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Teta', 'is_hidden' => true]);
        $foto->people()->attach($skryta->id);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => [
            'people' => ['makinka', 'Babička Jana'],
        ]]]])->assertOk();

        $this->assertSame(2, Person::withoutGlobalScopes()->where('gallery_space_id', $this->prostor->id)->where('is_hidden', false)->count(), 'Makinka se nezdvojila.');
        $this->assertEqualsCanonicalizing(['Makinka', 'Babička Jana', 'Teta'], $foto->people()->pluck('name')->all());
        $this->assertSame($this->adri->id, (int) $foto->people()->where('people.id', $makinka->id)->first()->pivot->tagged_by);

        // Dlaždice ukazuje jen viditelné osoby; v Lidech je nová osoba.
        $data = $this->actingAs($this->adri)->getJson('/api/data/knihovna')->assertOk()->json('data');
        $this->assertSame(['Babička Jana', 'Makinka'], collect($data['PHOTOS'])->firstWhere('id', $foto->uuid)['people']);
        $this->assertArrayHasKey('Babička Jana', $data['PERSONS']);

        // Odebrání ze seznamu osobu z fotky sundá, skrytá zůstane.
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => ['people' => ['Makinka']]]]])->assertOk();
        $this->assertEqualsCanonicalizing(['Makinka', 'Teta'], $foto->people()->pluck('name')->all());
    }

    /** Opakovaně poslaný stejný seznam úprav nepřepíše změnu udělanou jinde. */
    public function test_nezmenena_uprava_se_znovu_nepropisuje(): void
    {
        $foto = $this->fotka();
        $uprava = ['edits' => [$foto->uuid => ['place' => 'Pustevny']]];

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => $uprava])->assertOk();
        $foto->update(['location_name' => 'Radhošť']);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => $uprava])->assertOk();

        $this->assertSame('Radhošť', $foto->fresh()->location_name);
    }

    public function test_cizi_fotka_se_nezmeni(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $foto = $this->fotka(['gallery_space_id' => $ciziProstor->id]);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => ['caption' => 'Moje']], 'favs' => [$foto->uuid => true]]])->assertOk();

        $this->assertNull($foto->fresh()->caption);
        $this->assertFalse(DB::table('user_favorites')->where('media_item_id', $foto->id)->exists());
    }

    private function fotka(array $navic = []): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg',
            'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'taken_at' => '2026-09-01 10:00:00',
            'uploaded_at' => '2026-09-01 11:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
