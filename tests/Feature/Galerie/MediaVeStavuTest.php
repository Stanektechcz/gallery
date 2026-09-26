<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SelhavajiciTabulka;
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
    use SelhavajiciTabulka;

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

    /**
     * Zápis, který spadne, se zopakuje při dalším požadavku.
     *
     * Dřív byl pryč natrvalo: rozdíl se počítá proti stavu před uložením a stav
     * se uložil tak jako tak, takže při dalším požadavku nebylo co zapsat.
     * Obrazovka dál ukazovala srdíčko, které v databázi nikdy nebylo.
     *
     * Chyba databáze se napodobuje tabulkou, na které každý dotaz spadne
     * (`SelhavajiciTabulka`). Dřív se tabulka zahodila a založila znovu — na
     * MySQL to transakci testu potvrdilo a tabulka se vrátila bez cizích klíčů.
     */
    public function test_neuspesny_zapis_se_zopakuje_pri_dalsim_pozadavku(): void
    {
        $foto = $this->fotka();

        $this->rozbijTabulku('user_favorites');

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['favs' => [$foto->uuid => true], 'grid' => 'big']])
            ->assertOk();

        // Zbytek patche se uložil — chyba u srdíček nesmí vzít všechno ostatní.
        $this->assertSame('big', $this->stav()->data['grid']);
        // Dluh nese i hodnotu: `favs` se do sdíleného stavu neukládá, takže by
        // se při dalším požadavku nebylo odkud dozvědět, co se mělo zapsat.
        // A nese autora: srdíčka jsou každého vlastní, dluh leží ve sdíleném stavu.
        $this->assertSame([$foto->uuid => true], (array) $this->stav()->dluh()['favs:'.$this->adri->id]);

        $this->opravTabulku('user_favorites');

        // Další požadavek už o srdíčku vůbec nemluví, a přece se zapíše.
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['sort' => 'asc']])->assertOk();

        $this->assertTrue(
            DB::table('user_favorites')->where('user_id', $this->adri->id)->where('media_item_id', $foto->id)->exists(),
            'Dluh se má zaplatit i tehdy, když klient o tom klíči už nic neposílá.',
        );
        $this->assertSame([], $this->stav()->dluh());
    }

    /** Dluh je serverová věc — klient ho nedostane a nesmí ho zrušit. */
    public function test_dluh_se_klientovi_neposila_a_neda_se_od_nej_nastavit(): void
    {
        CoupleState::forCouple($this->prostor->id)->zapisDluh(['favs' => []]);

        $odpoved = $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['__dluh' => [], 'grid' => 'med']])->assertOk();

        $this->assertArrayNotHasKey('__dluh', (array) $odpoved->json('data'));
        $this->assertSame([], $this->stav()->dluh(),
            'Dluh se zaplatil hned v tomhle požadavku, ne proto, že ho klient smazal.');
    }

    /**
     * Srdíčka jsou každého vlastní a ve sdíleném stavu nemají co dělat.
     *
     * V databázi per-uživatele jsou (`user_favorites`), jenže `favs` se
     * ukládalo i do společného stavu dvojice — a klient ho čte **přednostně**
     * před serverovým příznakem. Druhý pak viděl cizích čtyřicet srdíček jako
     * svá; když některé odebral, zmizelo na obrazovce tomu prvnímu, i když
     * jeho řádek v databázi zůstal.
     *
     * Klíč se proto přijímá a zapisuje, ale neukládá — `persistSkip()`
     * v prohlížeči by ho naopak vůbec neodeslal a srdíčka by se přestala
     * ukládat úplně.
     */
    public function test_srdicka_nezustanou_ve_sdilenem_stavu(): void
    {
        $foto = $this->fotka();

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['favs' => [$foto->uuid => true], 'grid' => 'big']])
            ->assertOk();

        $this->assertTrue(
            DB::table('user_favorites')->where('user_id', $this->adri->id)->where('media_item_id', $foto->id)->exists(),
            'Srdíčko se pořád musí zapsat do databáze — jen ne do sdíleného stavu.',
        );

        $this->assertArrayNotHasKey('favs', (array) $this->stav()->data);
        $this->assertArrayNotHasKey('favs', (array) $this->actingAs($this->adri)
            ->getJson('/api/state')->assertOk()->json('data'));
    }

    private function stav(): CoupleState
    {
        return CoupleState::where('couple_id', $this->prostor->id)->sole();
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
