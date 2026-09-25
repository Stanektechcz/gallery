<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Co smí prohlížeč (a proxy po cestě) u souborů a obsahu galerie podržet.
 *
 * `response()->file()` i `download()` si po nastavení hlaviček samy přepnou
 * odpověď na `public` — video a archiv fotek tak mohla uložit sdílená
 * mezipaměť. Originál z trezoru a obsah se stavem trezoru nesmí zůstat
 * v paměti prohlížeče ani po zamčení.
 */
class HlavickyMediiTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        [$this->vlastnik, , , $this->prostor] = $this->dvojiceSHostem();
        Sanctum::actingAs($this->vlastnik);
    }

    public function test_video_neni_verejne(): void
    {
        $video = $this->fotka($this->prostor, $this->vlastnik, 'a.mp4', ['media_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $this->varianta($video, 'video_compat');

        $hlavicka = (string) $this->get(URL::temporarySignedRoute('galerie.media.video', now()->addDay(), ['uuid' => $video->uuid]))
            ->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $hlavicka);
        $this->assertStringNotContainsString('public', $hlavicka);
    }

    public function test_archiv_neni_verejny(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg');
        $this->varianta($fotka, 'original');

        $hlavicka = (string) $this->post('/api/media/archiv', ['ids' => [$fotka->uuid]])->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $hlavicka);
        $this->assertStringNotContainsString('public', $hlavicka);
    }

    public function test_original_z_trezoru_se_neuklada(): void
    {
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas.jpg', ['is_hidden' => true]);
        $this->varianta($skryta, 'original');

        $hlavicka = (string) $this->sOdemcenymTrezorem($this->vlastnik)->get('/api/media/'.$skryta->uuid.'/raw')
            ->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $hlavicka);
        $this->assertStringNotContainsString('public', $hlavicka);
    }

    /** Koš originály nevydává — po vyhození nemá fotka jít stáhnout jako by nic. */
    public function test_original_z_kose_se_nevyda(): void
    {
        $vKosi = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg', ['trashed_at' => now()]);
        $this->varianta($vKosi, 'original');

        $this->get('/api/media/'.$vKosi->uuid.'/raw')->assertNotFound();
    }

    public function test_uprava_jen_pro_cteni_neprojde(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg');
        $this->vlastnik->forceFill(['read_only_mode' => true])->save();

        $this->postJson('/api/media/'.$fotka->uuid.'/uprava', ['otoceni' => 90, 'vyrez' => false])->assertForbidden();
    }

    /**
     * Obsah se stavem trezoru a zámku se do paměti prohlížeče neukládá.
     *
     * `Vary` by nepomohl: po zamčení trezoru jde tentýž požadavek se stejnými
     * hlavičkami a prohlížeč by třicet sekund vracel odemčený obsah.
     */
    public function test_obsah_s_trezorem_se_neuklada_ostatni_jen_pro_ucet(): void
    {
        $system = (string) $this->getJson('/api/data/system')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $system);

        $davka = (string) $this->getJson('/api/data?skupiny=dnes,knihovna')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $davka);

        // Ostatní se smí uložit, ale ne vydat bez ověření — po přepnutí účtu
        // by jinak druhý viděl obsah prvního (`Vary` přepisuje middleware Inertie).
        $dnes = (string) $this->getJson('/api/data/dnes')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $dnes);
        $this->assertStringContainsString('private', $dnes);
        $this->assertStringNotContainsString('max-age=30', $dnes);
    }

    private function varianta(MediaItem $m, string $typ): void
    {
        $cesta = 'media/'.$m->uuid.'-'.$typ.'.bin';
        Storage::disk('public')->put($cesta, 'nejaka data');

        DB::table('media_variants')->insert([
            'media_item_id' => $m->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta,
            'size_bytes' => 11, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
