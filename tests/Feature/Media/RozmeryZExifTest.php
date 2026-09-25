<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Models\MediaItem;
use App\Services\Media\ExifExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rozměry z EXIF: jen když chybí, a otočené podle orientace.
 *
 * `ImageWidth/ImageHeight` jsou rozměry senzoru před otočením. iPhone na
 * výšku (Orientation 6) tak skončil jako 4032×3024 — a to i tehdy, když
 * správné rozměry už fotka měla.
 */
class RozmeryZExifTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->zalozProstor();
    }

    private function sExif(array $atributy, array $exif): MediaItem
    {
        $media = $this->media($atributy);
        $cesta = "media/{$media->uuid}/original.{$media->extension}";
        Storage::disk('public')->put($cesta, 'obsah');
        $this->varianta($media, 'original', $cesta);
        $this->mock(ExifExtractionService::class, fn ($mock) => $mock->shouldReceive('extract')->andReturn($exif));

        app()->call([new ExtractMediaMetadataJob($media->id), 'handle']);

        return $media->fresh();
    }

    public function test_fotka_na_vysku_ma_prohozene_rozmery(): void
    {
        $fotka = $this->sExif([], ['width' => 4032, 'height' => 3024, 'orientation' => 6]);

        $this->assertSame([3024, 4032], [(int) $fotka->width, (int) $fotka->height]);
    }

    public function test_existujici_rozmery_se_neprepisuji(): void
    {
        $fotka = $this->sExif(['width' => 3024, 'height' => 4032], ['width' => 4032, 'height' => 3024, 'orientation' => 1]);

        $this->assertSame([3024, 4032], [(int) $fotka->width, (int) $fotka->height]);
    }

    public function test_video_otocene_o_90_stupnu(): void
    {
        $video = $this->sExif(
            ['media_type' => 'video', 'extension' => 'mov', 'mime_type' => 'video/quicktime'],
            ['width' => 1920, 'height' => 1080, 'rotation' => 90],
        );

        $this->assertSame([1080, 1920], [(int) $video->width, (int) $video->height]);
    }
}
