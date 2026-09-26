<?php

namespace App\Jobs\Media;

use App\Jobs\Media\Concerns\NajdeZdrojMedia;
use App\Models\MediaItem;
use App\Services\Media\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Kopie videa pro prohlížeč (H.264 + AAC).
 *
 * Zdroj hledá stejně jako sourozenecké úlohy (`NajdeZdrojMedia`): dočasný
 * soubor nahrávky, jinak uložený originál. Dřív brala jen
 * `UploadSession::assembled_path`, který souběžné nahrávání na Drive po
 * dokončení maže — úloha pak tiše skončila a video z iPhonu (HEVC) se
 * v prohlížeči nepřehrálo nikdy.
 */
class GenerateVideoCompatibilityVariantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NajdeZdrojMedia, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Hodina na převod dlouhého videa z telefonu.
     *
     * Samotný ffmpeg má vlastní strop (`VideoProcessingService::STROP_PREVODU`,
     * 3300 s) — skončí dřív, než úlohu zabije worker, a po sobě uklidí. Dřív
     * tu bylo 1800 s a převod žádný strop neměl: dlouhé video worker zabil
     * uprostřed zápisu a ffmpeg běžel dál bez dozoru. `retry_after` databázové
     * fronty (3900 s) musí zůstat delší, jinak si převod vezme druhý worker
     * vedle prvního — hlídá `tests/Feature/FrontaTest.php`.
     */
    public int $timeout = 3600;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(VideoProcessingService $videoService): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (! $media) {
            return;
        }

        $path = $this->zdrojovySoubor($media);

        if (! $path) {
            Log::warning("Zdroj videa pro kompatibilní kopii nebyl nalezen, media #{$media->id}");

            return;
        }

        $videoService->generateCompatibilityVariant($media, $path);
    }
}
