<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\GenerateVideoCompatibilityVariantJob;
use App\Services\Media\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kopie pro prohlížeč (HEVC → H.264) vznikne i bez dočasného souboru nahrávky.
 *
 * Úloha brala jen `UploadSession::assembled_path`. Ten souběžné nahrávání na
 * Drive po dokončení maže a prototyp relaci nezakládá vůbec — úloha pak tiše
 * skončila a video z iPhonu se v prohlížeči nikdy nepřehrálo.
 */
class KompatibilniVideoTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    public function test_kopie_vznikne_z_ulozeneho_originalu(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media(['media_type' => 'video', 'extension' => 'mov', 'mime_type' => 'video/quicktime']);
        $cesta = "media/{$video->uuid}/original.mov";
        Storage::disk('public')->put($cesta, 'video');
        $this->varianta($video, 'original', $cesta);
        $ocekavana = Storage::disk('public')->path($cesta);

        $this->mock(VideoProcessingService::class, fn ($mock) => $mock->shouldReceive('generateCompatibilityVariant')
            ->once()
            ->withArgs(fn ($media, string $zdroj) => $media->id === $video->id && realpath($zdroj) === realpath($ocekavana))
            ->andReturn(null));

        app()->call([new GenerateVideoCompatibilityVariantJob($video->id), 'handle']);
    }
}
