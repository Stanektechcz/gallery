<?php

namespace App\Jobs\Media;

use App\Jobs\Media\Concerns\NajdeZdrojMedia;
use App\Models\MediaItem;
use App\Services\Media\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    /**
     * @param  bool  $force  Přepočítat i tehdy, když platná kopie už existuje.
     *                       Bez toho by ruční opravu (`gallery:videos --rebuild-compat`)
     *                       nešlo přes tuhle úlohu vůbec spustit.
     */
    public function __construct(private readonly int $mediaItemId, private readonly bool $force = false) {}

    /**
     * Zámek na úrovni jednoho média, ne fronty jako celku.
     *
     * Dvě kopie téže úlohy (oprava náhledů po sobě spuštěná dvakrát,
     * souběžný re-import) dřív sdílely stejný dočasný soubor
     * `compat_{uuid}.mp4` — druhá ho ve svém `finally` smazala pod rukama
     * první. `dontRelease()`: druhý pokus se má zahodit, ne čekat ve frontě
     * na uvolnění zámku a spustit se hned po první kopii znovu. Když první
     * úloha umře bez uložení kopie (restart serveru, zabitý worker), nic se
     * neztratí: fronta ji po `retry_after` (3900 s) vydá znovu a zámek je
     * v tu chvíli už pryč. Platnost
     * zámku (3700 s) je nad `VideoProcessingService::STROP_PREVODU` (3300 s)
     * i nad `$timeout` téhle úlohy s rezervou — kratší by uvolnil zámek dřív,
     * než doopravdy dlouhý převod doběhne.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('video-compat:'.$this->mediaItemId))->dontRelease()->expireAfter(3700)];
    }

    public function handle(VideoProcessingService $videoService): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (! $media) {
            return;
        }

        if (! $this->force && $this->maPlatnouKopii($media)) {
            return;
        }

        $path = $this->zdrojovySoubor($media);

        if (! $path) {
            Log::warning("Zdroj videa pro kompatibilní kopii nebyl nalezen, media #{$media->id}");

            return;
        }

        $videoService->generateCompatibilityVariant($media, $path);
    }

    /**
     * Existuje kompatibilní kopie a soubor za ní opravdu leží na disku?
     *
     * Oprava náhledů (`RepairAlbumMediaPreviewsJob`) i dokončení uploadu
     * pouští `GenerateVideoPosterJob`, který tuhle úlohu posílá bez podmínky —
     * bez kontroly se tak dlouhý převod opakoval při každé opravě náhledu,
     * i když kopie k přehrávání dávno existovala.
     */
    private function maPlatnouKopii(MediaItem $media): bool
    {
        $varianta = $media->variants()->where('type', 'video_compat')->first();

        return $varianta !== null && Storage::disk($varianta->disk)->exists($varianta->path);
    }
}
