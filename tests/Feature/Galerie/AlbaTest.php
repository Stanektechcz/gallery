<?php

namespace Tests\Feature\Galerie;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Jobs\Drive\MoveDriveFolderJob;
use App\Jobs\Drive\RenameDriveFolderJob;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alba z prototypu jdou do databáze.
 *
 * „Album vytvořeno" i „Zařadit do albumu" zapsaly jen do stavu v prohlížeči:
 * album nebylo v databázi ani na Disku a fotky v něm ležely jen na jedné
 * obrazovce.
 */
class AlbaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_album_vznikne_i_s_vybranymi_fotkami_a_slozkou_na_disku(): void
    {
        $a = $this->fotka();
        $b = $this->fotka();

        $odpoved = $this->postJson('/api/alba', ['nazev' => 'Pálava', 'media' => [$a->uuid, $b->uuid]])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $album = Album::where('uuid', $odpoved->json('album'))->sole();

        $this->assertSame('Pálava', $album->title);
        $this->assertSame($this->prostor->id, $album->gallery_space_id);
        $this->assertSame(2, DB::table('album_media')->where('album_id', $album->id)->count());
        $this->assertSame($album->id, $a->fresh()->primary_album_id);
        Queue::assertPushed(CreateDriveFolderJob::class);
        // Knihovna se vrací rovnou, ať se album ukáže bez obnovení stránky.
        $this->assertContains('Pálava', collect($odpoved->json('data.ALBUMS'))->pluck('name')->all());
    }

    public function test_podalbum_pod_nadrazenym(): void
    {
        $rodic = $this->postJson('/api/alba', ['nazev' => 'Morava'])->assertOk()->json('album');

        $dite = $this->postJson('/api/alba', ['nazev' => 'Pálava', 'rodic' => $rodic])->assertOk()->json('album');

        $this->assertSame(Album::where('uuid', $rodic)->value('id'), Album::where('uuid', $dite)->value('parent_id'));
    }

    public function test_zaradit_a_vyjmout(): void
    {
        $foto = $this->fotka();
        $album = $this->postJson('/api/alba', ['nazev' => 'Pálava'])->assertOk()->json('album');

        $odpoved = $this->postJson('/api/alba/zaradit', ['album' => $album, 'media' => [$foto->uuid]])->assertOk();
        $this->assertNotNull($foto->fresh()->primary_album_id);
        // Počet v albu se přepočítá — jinak stálo „0 položek".
        $this->assertSame('1 položka', collect($odpoved->json('data.ALBUMS'))->firstWhere('id', $album)['count']);

        $this->postJson('/api/alba/zaradit', ['album' => null, 'media' => [$foto->uuid]])->assertOk();
        $this->assertNull($foto->fresh()->primary_album_id);
        $this->assertSame(0, DB::table('album_media')->where('media_item_id', $foto->id)->count());
    }

    /** Cizí fotku ani do cizího alba zařadit nejde. */
    public function test_cizi_prostor_nejde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziFoto = $this->fotka(['gallery_space_id' => $ciziProstor->id]);
        $ciziAlbum = Album::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id, 'title' => 'Cizí', 'slug' => 'cizi',
            'created_by' => $cizi->id,
        ]);

        $this->postJson('/api/alba/zaradit', ['album' => $ciziAlbum->uuid, 'media' => [$ciziFoto->uuid]])->assertNotFound();

        $album = $this->postJson('/api/alba', ['nazev' => 'Moje', 'media' => [$ciziFoto->uuid]])->assertOk()->json('album');
        $this->assertSame(0, DB::table('album_media')->where('album_id', Album::where('uuid', $album)->value('id'))->count());
        $this->assertNull($ciziFoto->fresh()->primary_album_id);
    }

    // ——— správa alba z panelu (dřív jen stav prohlížeče) ———

    /** Přejmenování jde do databáze i na Disk. */
    public function test_udaje_alba_se_ulozi_a_slozka_na_disku_prejmenuje(): void
    {
        $album = $this->postJson('/api/alba', ['nazev' => 'Pálava'])->assertOk()->json('album');

        $odpoved = $this->patchJson('/api/alba/'.$album, ['nazev' => 'Pálava 2026', 'misto' => 'Mikulov', 'datum' => '2026-09-05', 'popis' => 'Vinobraní'])
            ->assertOk();

        $radek = Album::where('uuid', $album)->sole();
        $this->assertSame('Pálava 2026', $radek->title);
        $this->assertSame('Mikulov', $radek->location_name);
        $this->assertSame('Vinobraní', $radek->description);
        $this->assertSame('2026-09-05', $radek->event_date_start->toDateString());
        Queue::assertPushed(RenameDriveFolderJob::class);
        $this->assertContains('Pálava 2026', collect($odpoved->json('data.ALBUMS'))->pluck('name')->all(), json_encode($odpoved->json('data.ALBUMS')));
    }

    public function test_presun_alba_a_zakaz_vlozit_do_podalba(): void
    {
        $morava = $this->postJson('/api/alba', ['nazev' => 'Morava'])->json('album');
        $palava = $this->postJson('/api/alba', ['nazev' => 'Pálava'])->json('album');

        $this->postJson('/api/alba/'.$palava.'/presunout', ['rodic' => $morava])->assertOk();
        $this->assertSame(Album::where('uuid', $morava)->value('id'), Album::where('uuid', $palava)->value('parent_id'));
        Queue::assertPushed(MoveDriveFolderJob::class);

        // Morava do vlastního podalba nesmí.
        $this->postJson('/api/alba/'.$morava.'/presunout', ['rodic' => $palava])->assertStatus(422);

        // Zpět mezi hlavní alba.
        $this->postJson('/api/alba/'.$palava.'/presunout', ['rodic' => null])->assertOk();
        $this->assertNull(Album::where('uuid', $palava)->value('parent_id'));
    }

    public function test_titulni_fotka_jen_z_vlastni_viditelne_knihovny(): void
    {
        $foto = $this->fotka();
        $skryta = $this->fotka(['is_hidden' => true]);
        $album = $this->postJson('/api/alba', ['nazev' => 'Pálava', 'media' => [$foto->uuid, $skryta->uuid]])->json('album');

        $this->postJson('/api/alba/'.$album.'/titulni', ['foto' => $foto->uuid])->assertOk();
        $this->assertSame($foto->id, Album::where('uuid', $album)->value('cover_media_id'));

        $this->postJson('/api/alba/'.$album.'/titulni', ['foto' => $skryta->uuid])->assertStatus(422);
        $this->assertSame($foto->id, Album::where('uuid', $album)->value('cover_media_id'));
    }

    public function test_smazani_alba_nechá_fotky_a_jde_vratit(): void
    {
        $foto = $this->fotka();
        $album = $this->postJson('/api/alba', ['nazev' => 'Pálava', 'media' => [$foto->uuid]])->json('album');

        $this->deleteJson('/api/alba/'.$album)->assertOk();
        $this->assertSoftDeleted('albums', ['uuid' => $album]);
        $this->assertNull($foto->fresh()->trashed_at, 'Fotky alba zůstávají v knihovně.');

        $this->postJson('/api/alba/'.$album.'/obnovit')->assertOk();
        $this->assertNotSoftDeleted('albums', ['uuid' => $album]);
    }

    public function test_album_s_podalby_se_nesmaze(): void
    {
        $rodic = $this->postJson('/api/alba', ['nazev' => 'Morava'])->json('album');
        $this->postJson('/api/alba', ['nazev' => 'Pálava', 'rodic' => $rodic])->assertOk();

        $this->deleteJson('/api/alba/'.$rodic)->assertStatus(422);
        $this->assertNotSoftDeleted('albums', ['uuid' => $rodic]);
    }

    public function test_slouceni_presune_fotky_a_zdroj_zmizi(): void
    {
        $a = $this->fotka();
        $b = $this->fotka();
        $zdroj = $this->postJson('/api/alba', ['nazev' => 'Pálava', 'media' => [$a->uuid]])->json('album');
        $cil = $this->postJson('/api/alba', ['nazev' => 'Morava', 'media' => [$b->uuid]])->json('album');

        $this->postJson('/api/alba/'.$zdroj.'/sloucit', ['do' => $cil])->assertOk()->assertJsonPath('album', $cil);

        $cilId = Album::where('uuid', $cil)->value('id');
        $this->assertSame(2, DB::table('album_media')->where('album_id', $cilId)->count());
        $this->assertSame($cilId, $a->fresh()->primary_album_id);
        $this->assertSoftDeleted('albums', ['uuid' => $zdroj]);
        $this->assertSame(2, (int) Album::where('uuid', $cil)->value('media_count'));
    }

    public function test_cizi_album_spravovat_nejde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziAlbum = Album::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id, 'title' => 'Cizí', 'slug' => 'cizi-sprava',
            'created_by' => $cizi->id,
        ]);

        $this->patchJson('/api/alba/'.$ciziAlbum->uuid, ['nazev' => 'Moje'])->assertNotFound();
        $this->deleteJson('/api/alba/'.$ciziAlbum->uuid)->assertNotFound();
        $this->assertSame('Cizí', $ciziAlbum->fresh()->title);
    }

    /**
     * Archivované album zmizí z Alb (i z kopie telefonu) a jde vrátit.
     *
     * „Archivovat album" dřív schovalo album jen v jednom prohlížeči a Archiv,
     * o kterém mluvila hláška, neexistoval.
     */
    public function test_album_jde_archivovat_a_vratit(): void
    {
        $foto = $this->fotka();
        $uuid = $this->postJson('/api/alba', ['nazev' => 'Svatba Kláry', 'media' => [$foto->uuid]])->json('album');

        $odpoved = $this->postJson('/api/alba/'.$uuid.'/archivovat', ['archivovat' => true])->assertOk();

        $this->assertNotContains('Svatba Kláry', collect($odpoved->json('data.ALBUMS'))->pluck('name')->all());
        $this->assertNotContains('Svatba Kláry', collect($odpoved->json('data.MOBIL.ALBUMS'))->pluck('name')->all());
        $this->assertSame('Svatba Kláry', $odpoved->json('data.ALBUMS_ARCH.0.name'));
        $this->assertStringStartsWith('archivováno ', $odpoved->json('data.ALBUMS_ARCH.0.when'));
        // Fotka v archivovaném albu zůstává.
        $this->assertSame(1, DB::table('album_media')->count());

        $zpet = $this->postJson('/api/alba/'.$uuid.'/archivovat', ['archivovat' => false])->assertOk();
        $this->assertContains('Svatba Kláry', collect($zpet->json('data.ALBUMS'))->pluck('name')->all());
        // Prázdný archiv odpověď hlásí, aby klient nenechal starý seznam.
        $this->assertTrue($zpet->json('data.ALBUMS_ARCH') === [] || in_array('ALBUMS_ARCH', $zpet->json('prazdne'), true));
    }

    /**
     * „Duplikovat strukturu" založí skutečná alba i se stromem podalb.
     *
     * Kopie žila jen ve stavu prohlížeče: nešlo do ní nic zařadit a každá
     * další akce na ní selhala, protože neměla skutečné id.
     */
    public function test_duplikovat_strukturu_zalozi_alba_na_serveru(): void
    {
        $foto = $this->fotka();
        $cesta = $this->postJson('/api/alba', ['nazev' => 'Chorvatsko', 'media' => [$foto->uuid]])->json('album');
        $zadar = $this->postJson('/api/alba', ['nazev' => 'Zadar', 'rodic' => $cesta])->json('album');
        $this->postJson('/api/alba', ['nazev' => 'Staré město', 'rodic' => $zadar])->assertOk();

        $odpoved = $this->postJson('/api/alba/'.$cesta.'/duplikovat')->assertOk()->assertJsonPath('ok', true);

        $kopie = Album::where('uuid', $odpoved->json('album'))->sole();
        $this->assertSame('Chorvatsko (kopie)', $kopie->title);
        $this->assertNull($kopie->parent_id);
        $zadarKopie = Album::where('parent_id', $kopie->id)->sole();
        $this->assertSame('Zadar', $zadarKopie->title);
        $this->assertSame('Staré město', Album::where('parent_id', $zadarKopie->id)->value('title'));
        $this->assertStringContainsString('2 podalb', $odpoved->json('zprava'));
        // Fotky zůstávají jen v originálu.
        $this->assertSame(0, DB::table('album_media')->where('album_id', $kopie->id)->count());
        $this->assertSame(1, DB::table('album_media')->count());
    }

    /** Podalbum, na které se dlouho nesáhlo, v detailu rodiče nechybí. */
    public function test_podalba_i_mimo_posledni_upravena(): void
    {
        $rodic = $this->postJson('/api/alba', ['nazev' => 'Beskydy'])->json('album');
        $dite = $this->postJson('/api/alba', ['nazev' => 'Pustevny', 'rodic' => $rodic])->json('album');
        Album::where('uuid', $dite)->update(['updated_at' => now()->subYears(3)]);
        Album::where('uuid', $rodic)->update(['updated_at' => now()->addMinute()]);
        for ($i = 0; $i < 205; $i++) {
            DB::table('albums')->insert([
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'title' => 'Výplň '.$i, 'slug' => 'vypln-'.$i, 'visibility' => 'shared', 'depth' => 0,
                'materialized_path' => '/vypln-'.$i, 'full_display_path' => 'Výplň '.$i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $alba = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.ALBUMS'))->keyBy('id');

        $this->assertArrayNotHasKey($dite, $alba->all(), 'Podalbum je mimo posledních dvě stě upravených.');
        $this->assertSame(['Pustevny'], array_column($alba[$rodic]['children'], 'name'));
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
