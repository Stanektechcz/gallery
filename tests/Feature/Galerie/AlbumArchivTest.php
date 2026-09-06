<?php

namespace Tests\Feature\Galerie;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Album jako jeden archiv.
 *
 * Tlačítko „Stáhnout" v panelu alba nemělo obsluhu vůbec. Stahovat po jednom
 * nejde — u alba s dvěma sty fotkami by prohlížeč po pár souborech zbytek
 * zablokoval.
 */
class AlbumArchivTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    /** Archiv nese originály, které aplikace opravdu má u sebe. */
    public function test_archiv_nese_originaly(): void
    {
        $album = $this->album('Beskydy');
        $this->doAlba($album, $this->fotka('IMG_1.jpg', true));
        $this->doAlba($album, $this->fotka('IMG_2.jpg', true));

        $odpoved = $this->get('/api/alba/'.$album->uuid.'/archiv')->assertOk();

        $this->assertSame('application/zip', $odpoved->headers->get('content-type'));
        $this->assertStringContainsString('beskydy.zip', (string) $odpoved->headers->get('content-disposition'));

        $jmena = $this->vArchivu($odpoved);
        $this->assertContains('IMG_1.jpg', $jmena);
        $this->assertContains('IMG_2.jpg', $jmena);
    }

    /**
     * Co u sebe aplikace nemá, se do archivu nedostane — ale řekne se to.
     *
     * Tiché vynechání by znamenalo, že dvojice má „zálohu alba", ve které
     * polovina fotek chybí a nikde to nestojí.
     */
    public function test_chybejici_originaly_maji_v_archivu_soupis(): void
    {
        $album = $this->album('Beskydy');
        $this->doAlba($album, $this->fotka('MAM.jpg', true));
        $this->doAlba($album, $this->fotka('NEMAM.jpg', false));

        $odpoved = $this->get('/api/alba/'.$album->uuid.'/archiv')->assertOk();
        $jmena = $this->vArchivu($odpoved);

        $this->assertContains('MAM.jpg', $jmena);
        $this->assertContains('CHYBI.txt', $jmena);
        $this->assertNotContains('NEMAM.jpg', $jmena);
    }

    /** Dvě fotky téhož jména se v archivu nepřepíšou. */
    public function test_stejna_jmena_se_v_archivu_neprepisou(): void
    {
        $album = $this->album('Beskydy');
        $this->doAlba($album, $this->fotka('IMG_1.jpg', true));
        $this->doAlba($album, $this->fotka('IMG_1.jpg', true));

        $jmena = $this->vArchivu($this->get('/api/alba/'.$album->uuid.'/archiv')->assertOk());

        $this->assertContains('IMG_1.jpg', $jmena);
        $this->assertContains('IMG_1 (2).jpg', $jmena);
    }

    /** Album bez jediného originálu není archiv, je to nedorozumění. */
    public function test_album_bez_originalu_je_ctyristacityri(): void
    {
        $album = $this->album('Prázdné');
        $this->doAlba($album, $this->fotka('NEMAM.jpg', false));

        $this->get('/api/alba/'.$album->uuid.'/archiv')->assertNotFound();
    }

    /** Cizí album se stáhnout nedá. */
    public function test_cizi_album_se_nestahne(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $album = Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $ciziProstor->id,
            'title' => 'Cizí album',
            'slug' => 'cizi-album',
            'created_by' => $cizi->id,
        ]);

        $this->get('/api/alba/'.$album->uuid.'/archiv')->assertNotFound();
    }

    /** @return list<string> */
    private function vArchivu($odpoved): array
    {
        $soubor = tempnam(sys_get_temp_dir(), 'test_').'.zip';
        file_put_contents($soubor, $odpoved->streamedContent());

        $zip = new ZipArchive;
        $zip->open($soubor);

        $jmena = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $jmena[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($soubor);

        return $jmena;
    }

    private function album(string $nazev): Album
    {
        return Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => $nazev,
            'slug' => Str::slug($nazev).'-'.Str::random(4),
            'created_by' => $this->adri->id,
        ]);
    }

    private function fotka(string $jmeno, bool $sOriginalem): MediaItem
    {
        $m = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $jmeno,
            'safe_filename' => Str::slug($jmeno),
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ]);

        if ($sOriginalem) {
            $cesta = 'media/'.$m->uuid.'/original.jpg';
            Storage::disk('public')->put($cesta, 'obsah '.$jmeno);

            DB::table('media_variants')->insert([
                'media_item_id' => $m->id,
                'type' => 'original',
                'disk' => 'public',
                'path' => $cesta,
                'size_bytes' => 1024,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $m;
    }

    private function doAlba(Album $album, MediaItem $m): void
    {
        DB::table('album_media')->insert([
            'album_id' => $album->id,
            'media_item_id' => $m->id,
            'sort_order' => $m->id,
            'added_at' => now(),
        ]);
    }
}
