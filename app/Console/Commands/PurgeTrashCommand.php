<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Koš po třiceti dnech.
 *
 * Do té doby jde všechno vrátit — teprve pak se originál smaže z disku.
 *
 * Aplikace dosud `purge_after` jen zapisovala a nikdo podle něj neuklízel:
 * smazaná fotka zůstávala na disku i v součtu úložiště navždy, takže dvojice
 * platila za místo, které podle obrazovky uvolnila. Datum úklidu se nastavuje
 * při mazání a `--dny` ho jen dorovná pro řádky, kterým chybí.
 */
class PurgeTrashCommand extends Command
{
    protected $signature = 'gallery:purge-trash {--dny= : Přepíše lhůtu z config/gallery.php} {--nasucho : Jen vypíše, co by se smazalo}';

    protected $description = 'Trvale smaže položky, které jsou v koši déle než nastavená lhůta';

    public function handle(): int
    {
        $dny = (int) ($this->option('dny') ?: config('gallery.trash_retention_days', 30));
        $nasucho = (bool) $this->option('nasucho');
        $hranice = now()->subDays($dny);

        // Řádek bez `purge_after` (starší mazání) se posuzuje podle `trashed_at`,
        // jinak by v koši zůstal navždy.
        $fronta = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereNotNull('trashed_at')
            ->where(function ($dotaz) use ($hranice) {
                $dotaz->where('purge_after', '<=', now())
                    ->orWhere(fn ($bez) => $bez->whereNull('purge_after')->where('trashed_at', '<=', $hranice));
            });

        $smazano = 0;
        $bajtu = 0;

        $fronta->with('variants')->each(function (MediaItem $media) use ($nasucho, &$smazano, &$bajtu) {
            if ($nasucho) {
                $this->line('  '.$media->uuid.'  '.$media->original_filename);
                $smazano++;
                $bajtu += (int) $media->size_bytes;

                return;
            }

            AuditLog::record('media.purge', $media, [
                'filename' => $media->original_filename,
                'duvod' => 'lhůta koše',
            ]);

            $this->smazSoubory($media);
            // `forceDelete`, ne `delete`: model má soft delete, a měkce smazaný
            // řádek by dál držel místo v součtu i v databázi.
            $media->forceDelete();

            $smazano++;
            $bajtu += (int) $media->size_bytes;
        });

        $this->info($smazano
            ? ($nasucho ? 'Ke smazání: ' : 'Smazáno: ').$smazano.' položek · '.round($bajtu / 1048576, 1).' MB'
            : 'Koš je prázdný.');

        return self::SUCCESS;
    }

    private function smazSoubory(MediaItem $media): void
    {
        foreach ($media->variants as $varianta) {
            if (! $varianta->path) {
                continue;
            }

            try {
                Storage::disk($varianta->disk ?: 'public')->delete($varianta->path);
            } catch (\Throwable $e) {
                Log::warning('Soubor varianty se nepodařilo smazat', [
                    'path' => $varianta->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            Storage::disk('public')->deleteDirectory('media/'.$media->uuid);
        } catch (\Throwable $e) {
            Log::warning('Adresář média se nepodařilo smazat', ['uuid' => $media->uuid]);
        }

        $slozene = storage_path('app/uploads/'.$media->uuid);

        if (is_dir($slozene)) {
            array_map('unlink', glob($slozene.'/*') ?: []);
            @rmdir($slozene);
        }
    }
}
