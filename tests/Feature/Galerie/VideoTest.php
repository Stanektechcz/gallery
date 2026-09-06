<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Video se přehraje — místo obrázku s namalovaným pruhem.
 *
 * Prohlížeč fotky měl u videa pozadí, pod ním čas „0:42 z 2:04" pro každé
 * video stejný a dvě tlačítka (přehrát, zvuk) bez obsluhy. Nebylo co ovládat:
 * žádné `<video>` na stránce nebylo.
 */
class VideoTest extends TestCase
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

    /** Knihovna posílá adresu k přehrání a plakát. */
    public function test_knihovna_posle_adresu_videa(): void
    {
        $video = $this->video();
        $this->varianta($video, 'video_compat');
        $this->varianta($video, 'thumbnail');

        $radek = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'))
            ->firstWhere('id', $video->uuid);

        $this->assertNotNull($radek);
        $this->assertTrue($radek['isVideo']);
        $this->assertStringContainsString('/api/media/'.$video->uuid.'/video', $radek['video']);
        $this->assertStringContainsString('signature=', $radek['video'], 'Adresa musí být podepsaná — video si stahuje prohlížeč sám.');
        $this->assertStringContainsString('/api/media/'.$video->uuid.'/thumb', $radek['poster']);
    }

    /**
     * Bez souboru se adresa neposílá.
     *
     * Přehrávač, který ukáže černou plochu, je horší než věta o tom, že se
     * video ještě zpracovává.
     */
    public function test_bez_souboru_se_adresa_neposila(): void
    {
        $video = $this->video();

        $radek = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'))
            ->firstWhere('id', $video->uuid);

        $this->assertArrayNotHasKey('video', $radek);
        $this->assertArrayNotHasKey('poster', $radek);
    }

    /** Nepodepsaná adresa video nevydá. */
    public function test_bez_podpisu_to_neprojde(): void
    {
        $video = $this->video();
        $this->varianta($video, 'original');

        $this->get('/api/media/'.$video->uuid.'/video')->assertForbidden();
    }

    /** S podpisem se soubor vydá — a dá se v něm přeskakovat. */
    public function test_s_podpisem_se_video_vyda(): void
    {
        $video = $this->video();
        $this->varianta($video, 'video_compat');

        $odpoved = $this->get($this->adresa($video))->assertOk();

        $this->assertSame('video/mp4', $odpoved->headers->get('Content-Type'));
        $this->assertSame('bytes', $odpoved->headers->get('Accept-Ranges'), 'Bez částí se ve videu nedá přeskakovat.');
    }

    /** Fotka na téhle cestě není video. */
    public function test_fotka_na_ceste_videa_je_404(): void
    {
        $fotka = $this->video(['media_type' => 'photo', 'mime_type' => 'image/jpeg']);
        $this->varianta($fotka, 'original');

        $this->get($this->adresa($fotka))->assertNotFound();
    }

    /** Položka v trezoru se nepřehraje. */
    public function test_schovane_video_se_neprehraje(): void
    {
        $video = $this->video(['is_hidden' => true]);
        $this->varianta($video, 'original');

        $this->get($this->adresa($video))->assertNotFound();
    }

    // ——— pomůcky ———

    private function video(array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_2418.mp4',
            'safe_filename' => 'img-2418.mp4',
            'extension' => 'mp4',
            'mime_type' => 'video/mp4',
            'media_type' => 'video',
            'size_bytes' => 4096,
            'duration_ms' => 124000,
            'taken_at' => '2026-08-16 06:42:00',
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }

    private function varianta(MediaItem $m, string $typ): void
    {
        $cesta = 'media/'.$m->uuid.'-'.$typ.'.mp4';
        Storage::disk('public')->put($cesta, 'nejaka data');

        DB::table('media_variants')->insert([
            'media_item_id' => $m->id,
            'type' => $typ,
            'disk' => 'public',
            'path' => $cesta,
            'size_bytes' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function adresa(MediaItem $m): string
    {
        return URL::temporarySignedRoute('galerie.media.video', now()->addDay(), ['uuid' => $m->uuid]);
    }
}
