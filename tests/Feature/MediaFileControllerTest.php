<?php

namespace Tests\Feature;

use App\Jobs\Media\GenerateImageVariantsJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaFileControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Test', 'slug' => 'test-files', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_video_files_support_byte_ranges_for_fast_browser_playback(): void
    {
        Storage::disk('public')->put('media/video-uuid/video_compat.mp4', '0123456789');

        $response = $this->withHeader('Range', 'bytes=2-5')
            ->get(MediaVariant::proxyUrl('media/video-uuid/video_compat.mp4'));

        $response->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');
        $this->assertSame('2345', $response->streamedContent());
    }

    public function test_video_file_rejects_invalid_byte_ranges(): void
    {
        Storage::disk('public')->put('media/video-uuid/video.mp4', '0123456789');

        $this->withHeader('Range', 'bytes=20-30')
            ->get(MediaVariant::proxyUrl('media/video-uuid/video.mp4'))
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_missing_photo_thumbnail_returns_lightweight_preview_and_queues_repair(): void
    {
        Queue::fake();

        $media = $this->fotka();

        $this->get(MediaVariant::proxyUrl('media/'.$media->uuid.'/thumbnail.jpg'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Gallery-Preview-Repair', 'queued');

        Queue::assertPushed(GenerateImageVariantsJob::class);
    }

    // ——— kdo soubor dostane ———

    /**
     * Originál bez přihlášení a bez podpisu nedostane nikdo.
     *
     * Adresa `/files/media/{uuid}/original.jpg` vydávala fotku komukoli,
     * kdo znal uuid — a to je v každém sdíleném odkazu i v protokolu.
     */
    public function test_bez_podpisu_a_prihlaseni_soubor_neni(): void
    {
        $media = $this->fotka();
        Storage::disk('public')->put('media/'.$media->uuid.'/original.jpg', 'jpeg');

        $this->get('/files/media/'.$media->uuid.'/original?ext=jpg')->assertNotFound();
        $this->get('/files/hlasovky/'.$this->prostor->id.'/babicka.webm')->assertNotFound();
    }

    public function test_clen_prostoru_soubor_dostane_cizi_ne(): void
    {
        $media = $this->fotka();
        Storage::disk('public')->put('media/'.$media->uuid.'/original.jpg', 'jpeg');

        Sanctum::actingAs($this->adri);
        $odpoved = $this->get('/files/media/'.$media->uuid.'/original?ext=jpg')->assertOk();
        $this->assertStringContainsString('private', (string) $odpoved->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', (string) $odpoved->headers->get('Content-Security-Policy'));

        $cizi = User::factory()->create();
        GallerySpace::create(['name' => 'Cizí', 'slug' => 'cizi-files', 'owner_id' => $cizi->id])
            ->members()->syncWithoutDetaching([$cizi->id => ['role' => 'owner']]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($cizi);
        $this->get('/files/media/'.$media->uuid.'/original?ext=jpg')->assertNotFound();
    }

    /** Host prostoru má fotky jen z podepsaného odkazu, ne přes uuid. */
    public function test_host_prostoru_soubor_bez_podpisu_nedostane(): void
    {
        $media = $this->fotka();
        Storage::disk('public')->put('media/'.$media->uuid.'/original.jpg', 'jpeg');
        Storage::disk('public')->put('media/'.$media->uuid.'/thumbnail.jpg', 'jpeg');

        $host = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);

        Sanctum::actingAs($host);
        $this->get('/files/media/'.$media->uuid.'/original?ext=jpg')->assertNotFound();
        $this->get(MediaVariant::proxyUrl('media/'.$media->uuid.'/thumbnail.jpg'))->assertOk();
    }

    /** Fotka v trezoru se vydá jen s odemčeným trezorem — nebo podepsaná aplikací. */
    public function test_fotka_v_trezoru_jen_s_odemcenym_trezorem(): void
    {
        $media = $this->fotka(['is_hidden' => true]);
        Storage::disk('public')->put('media/'.$media->uuid.'/original.jpg', 'jpeg');

        $this->actingAs($this->adri)->get('/files/media/'.$media->uuid.'/original?ext=jpg')->assertNotFound();

        $this->actingAs($this->adri)
            ->withSession($this->odemcenyTrezor($this->adri))
            ->get('/files/media/'.$media->uuid.'/original?ext=jpg')
            ->assertOk();
    }

    /** Podepsaná adresa vydaná před přesunem do trezoru se zamčeným trezorem neotevře. */
    public function test_podepsana_adresa_fotky_v_trezoru_chce_odemceni(): void
    {
        $media = $this->fotka();
        Storage::disk('public')->put('media/'.$media->uuid.'/thumbnail.jpg', 'jpeg');
        $podepsana = MediaVariant::proxyUrl('media/'.$media->uuid.'/thumbnail.jpg');

        $media->forceFill(['is_hidden' => true])->save();

        $this->get($podepsana)->assertNotFound();

        // Odemčení patří člověku: s podpisem projde jen tomu, kdo trezor odemkl.
        $this->actingAs($this->adri)->withSession($this->odemcenyTrezor($this->adri))->get($podepsana)->assertOk();
    }

    /** Podepsaný náhled galerie fotku z trezoru nevydá vůbec — trezor náhledy neposílá. */
    public function test_nahled_galerie_fotku_z_trezoru_nevyda(): void
    {
        $media = $this->fotka();
        Storage::disk('public')->put('media/'.$media->uuid.'/thumbnail.jpg', 'jpeg');
        DB::table('media_variants')->insert([
            'media_item_id' => $media->id, 'type' => 'thumbnail', 'disk' => 'public',
            'path' => 'media/'.$media->uuid.'/thumbnail.jpg', 'size_bytes' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $podepsana = URL::temporarySignedRoute('galerie.media.thumb', now()->addDay(), ['uuid' => $media->uuid]);

        $this->get($podepsana)->assertOk();

        $media->forceFill(['is_hidden' => true])->save();
        $this->get($podepsana)->assertNotFound();
    }

    /** Fotka vyhozená do koše přes dřív vydanou adresu nejde otevřít. */
    public function test_nahled_galerie_fotku_z_kose_nevyda(): void
    {
        $media = $this->fotka();
        Storage::disk('public')->put('media/'.$media->uuid.'/thumbnail.jpg', 'jpeg');
        DB::table('media_variants')->insert([
            'media_item_id' => $media->id, 'type' => 'thumbnail', 'disk' => 'public',
            'path' => 'media/'.$media->uuid.'/thumbnail.jpg', 'size_bytes' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $podepsana = URL::temporarySignedRoute('galerie.media.thumb', now()->addDay(), ['uuid' => $media->uuid]);

        $this->get($podepsana)->assertOk();

        $media->forceFill(['trashed_at' => now()])->save();
        $this->get($podepsana)->assertNotFound();

        // Po obnovení z koše funguje zase.
        $media->forceFill(['trashed_at' => null])->save();
        $this->get($podepsana)->assertOk();
    }

    public function test_podepsana_adresa_neplati_pro_jiny_soubor(): void
    {
        $media = $this->fotka();
        Storage::disk('public')->put('media/'.$media->uuid.'/original.jpg', 'jpeg');
        Storage::disk('public')->put('media/'.$media->uuid.'/thumbnail.jpg', 'jpeg');

        $podepsana = MediaVariant::proxyUrl('media/'.$media->uuid.'/thumbnail.jpg');

        $this->get($podepsana)->assertOk();
        $this->get(str_replace('thumbnail', 'original', $podepsana))->assertNotFound();
    }

    private function fotka(array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => 'e95ce0f5-d27a-45b5-a11d-9c187a110c44',
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'photo.jpg',
            'safe_filename' => 'photo.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 100,
        ], $navic));
    }
}
