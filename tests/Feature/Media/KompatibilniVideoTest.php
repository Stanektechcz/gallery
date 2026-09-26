<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\GenerateVideoCompatibilityVariantJob;
use App\Services\Media\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * Platná kopie se nepřevádí znovu.
     *
     * `GenerateVideoPosterJob` posílá tuhle úlohu bez podmínky při každé
     * opravě náhledu (`RepairAlbumMediaPreviewsJob`, dokončení uploadu) —
     * bez kontroly existující varianty se tak hodinový převod opakoval,
     * i když kopie k přehrávání dávno ležela na disku.
     */
    public function test_existujici_kopie_se_neprevadi_znovu(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media(['media_type' => 'video', 'extension' => 'mov', 'mime_type' => 'video/quicktime']);
        $cesta = "media/{$video->uuid}/video_compat.mp4";
        Storage::disk('public')->put($cesta, 'hotova kopie');
        $this->varianta($video, 'video_compat', $cesta);

        $this->mock(VideoProcessingService::class, fn ($mock) => $mock->shouldNotReceive('generateCompatibilityVariant'));

        app()->call([new GenerateVideoCompatibilityVariantJob($video->id), 'handle']);
    }

    /** `force: true` (ruční `gallery:videos --rebuild-compat`) obejde existující kopii. */
    public function test_force_prevede_i_pres_existujici_kopii(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media(['media_type' => 'video', 'extension' => 'mov', 'mime_type' => 'video/quicktime']);
        $cesta = "media/{$video->uuid}/original.mov";
        Storage::disk('public')->put($cesta, 'video');
        $this->varianta($video, 'original', $cesta);
        $this->varianta($video, 'video_compat', "media/{$video->uuid}/video_compat.mp4");
        Storage::disk('public')->put("media/{$video->uuid}/video_compat.mp4", 'stara kopie');

        $this->mock(VideoProcessingService::class, fn ($mock) => $mock->shouldReceive('generateCompatibilityVariant')->once()->andReturn(null));

        app()->call([new GenerateVideoCompatibilityVariantJob($video->id, force: true), 'handle']);
    }

    /**
     * Zámek je na úroveň jednoho média, ne fronty jako celku.
     *
     * Dvě souběžné kopie téže úlohy (oprava náhledu spuštěná dvakrát, souběžný
     * re-import) sdílely stejný dočasný soubor `compat_{uuid}.mp4` — druhá ho
     * ve svém `finally` smazala pod rukama první.
     */
    public function test_zamek_proti_soubehu_je_na_konkretni_medium(): void
    {
        $uloha = new GenerateVideoCompatibilityVariantJob(42);
        $middleware = $uloha->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('video-compat:42', $middleware[0]->key);
        $this->assertGreaterThanOrEqual(3700, $middleware[0]->expiresAfter);
    }
}
