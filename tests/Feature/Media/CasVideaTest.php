<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Jobs\Media\GenerateVideoPosterJob;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Services\Media\ExifExtractionService;
use App\Services\Media\FilenameMetadataService;
use App\Services\Media\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Čas pořízení videa: pražské hodiny a nepřepisovat, co už našel EXIF.
 *
 * Úloha náhledu videa přepisovala `taken_at` bezpodmínečně — i datum, které
 * chvíli předtím správně vytáhla úloha EXIF — a navíc v UTC.
 */
class CasVideaTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->zalozProstor();

        // Co vrátí skutečný parser z `creation_time: 2026-07-01T22:30:00Z`
        // (viz VideoProcessingServiceTest) — hodiny v Praze.
        $this->mock(VideoProcessingService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('extractMetadata')->andReturn([
                'duration_ms' => 3500,
                'taken_at' => Carbon::parse('2026-07-02 00:30:00'),
            ]);
            $mock->shouldReceive('generatePoster')->andReturn(new MediaVariant);
            $mock->shouldReceive('generateCompatibilityVariant')->andReturn(null);
        });
    }

    private function video(?string $takenAt): MediaItem
    {
        $video = $this->media([
            'original_filename' => 'IMG_0001.MOV', 'safe_filename' => 'IMG_0001.MOV',
            'extension' => 'mov', 'mime_type' => 'video/quicktime', 'media_type' => 'video',
            'taken_at' => $takenAt, 'display_title' => 'Video',
        ]);
        $cesta = "media/{$video->uuid}/original.mov";
        Storage::disk('public')->put($cesta, 'video');
        $this->varianta($video, 'original', $cesta);

        return $video;
    }

    private function ulozenyCas(MediaItem $media): ?string
    {
        return DB::table('media_items')->where('id', $media->id)->value('taken_at');
    }

    public function test_prazdne_datum_se_doplni_v_prazskych_hodinach(): void
    {
        $video = $this->video(null);

        app()->call([new GenerateVideoPosterJob($video->id), 'handle']);

        $this->assertSame('2026-07-02 00:30:00', $this->ulozenyCas($video));
        $this->assertSame(3500, (int) $video->fresh()->duration_ms);
    }

    public function test_datum_ktere_uz_video_ma_zustane(): void
    {
        $video = $this->video('2026-06-01 10:00:00');

        app()->call([new GenerateVideoPosterJob($video->id), 'handle']);

        $this->assertSame('2026-06-01 10:00:00', $this->ulozenyCas($video));
    }

    public function test_bez_data_z_exif_nahradi_datum_z_prohlizece(): void
    {
        // `taken_at` z nahrávání je jen čas změny souboru v prohlížeči.
        $video = $this->video('2026-09-20 18:00:00');
        $this->mock(ExifExtractionService::class, fn ($mock) => $mock->shouldReceive('extract')->andReturn([]));

        app()->call([new ExtractMediaMetadataJob($video->id), 'handle']);

        $poster = null;
        Queue::assertPushed(GenerateVideoPosterJob::class, function ($job) use (&$poster) {
            $poster = $job;

            return true;
        });
        app()->call([$poster, 'handle']);

        $this->assertSame('2026-07-02 00:30:00', $this->ulozenyCas($video));
    }

    public function test_datum_z_exif_neprepise(): void
    {
        $video = $this->video('2026-09-20 18:00:00');
        $this->mock(ExifExtractionService::class, fn ($mock) => $mock->shouldReceive('extract')
            ->andReturn(['taken_at' => Carbon::parse('2026-07-02 00:29:58')]));

        app()->call([new ExtractMediaMetadataJob($video->id), 'handle']);

        $poster = null;
        Queue::assertPushed(GenerateVideoPosterJob::class, function ($job) use (&$poster) {
            $poster = $job;

            return true;
        });
        app()->call([$poster, 'handle']);

        $this->assertSame('2026-07-02 00:29:58', $this->ulozenyCas($video));
    }

    public function test_prikaz_videi_neprepise_existujici_datum(): void
    {
        $sDatem = $this->video('2026-06-01 10:00:00');
        $bezData = $this->video(null);
        $this->mock(FilenameMetadataService::class, fn ($mock) => $mock->shouldReceive('infer')->andReturn([]));

        $this->artisan('gallery:videos', ['--all' => true])->assertExitCode(0);

        $this->assertSame('2026-06-01 10:00:00', $this->ulozenyCas($sDatem));
        $this->assertSame('2026-07-02 00:30:00', $this->ulozenyCas($bezData));
    }
}
