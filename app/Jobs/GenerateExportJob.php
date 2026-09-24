<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        private readonly int $userId,
        private readonly array $options,
        private readonly string $jobId,
        private readonly int $spaceId = 0,
    ) {}

    /**
     * Které fotky do vývozu patří.
     *
     * Filtr podle prostoru tu stojí i přesto, že ho kontroler ověřuje taky:
     * úloha běží ve frontě, kde není přihlášený uživatel, takže globální
     * rozsah `SpaceContext` ustupuje — a bez tohohle `where` vrátil vývoz
     * s cizími identifikátory ZIP s fotkami jiné dvojice. Statická je proto,
     * aby na ni šel napsat test bez fronty a bez souborů na disku.
     *
     * Trezor a koš se do vývozu nedostanou: úloha nemá sezení, a tedy ani
     * odemčený trezor, a fotka z koše se dřív vracela v ZIPu, jako by nic.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, MediaItem>
     */
    public static function vybraneFotky(array $options, int $spaceId): Collection
    {
        if ($spaceId <= 0) {
            return collect();
        }

        $dotaz = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $spaceId)
            ->active();

        return match ($options['type'] ?? null) {
            'album' => $dotaz->where('primary_album_id', $options['target_id'] ?? 0)->get(),
            'selection' => $dotaz->whereIn('id', $options['media_ids'] ?? [])->get(),
            default => collect(),
        };
    }

    public function handle(): void
    {
        Cache::put("export_status_{$this->jobId}", 'processing', 3600);

        try {
            $user = User::find($this->userId);
            if (! $user) {
                return;
            }

            $media = self::vybraneFotky($this->options, $this->spaceId);

            $zipPath = storage_path("app/exports/{$this->jobId}.zip");
            @mkdir(dirname($zipPath), 0755, true);

            $zip = new \ZipArchive;
            $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            foreach ($media as $item) {
                // Nejlepší místní kopie. Originál je poslední v řadě schválně —
                // je mnohonásobně větší —, ale je v řadě: fotka bez zmenšenin se
                // dřív z vývozu tiše ztratila a ZIP se tvářil hotově. Po úklidu
                // variant kvůli místu na disku by se to dělo běžně.
                $variant = $item->getVariant('large')
                    ?? $item->getVariant('medium')
                    ?? $item->getVariant('original');
                if ($variant) {
                    $variantPath = Storage::disk('public')->path($variant->path);
                    if (file_exists($variantPath)) {
                        $zip->addFile($variantPath, $item->original_filename);
                    }
                }
            }

            $zip->close();

            Cache::put("export_status_{$this->jobId}", 'ready', 3600);
        } catch (\Throwable $e) {
            Cache::put("export_status_{$this->jobId}", 'failed', 3600);
            throw $e;
        }
    }
}
