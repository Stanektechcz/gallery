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

    public int $timeout = 1800; // 30 minutes for large videos

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
