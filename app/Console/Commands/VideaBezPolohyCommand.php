<?php

namespace App\Console\Commands;

use App\Models\MediaVariant;
use App\Services\Media\VideoProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Odstraní polohu ze starších kopií videí k přehrávání.
 *
 * Kopie (`video_compat`) se kóduje s `-map_metadata -1` teprve od chvíle,
 * kdy se zjistilo, že ffmpeg do ní přenáší polohu z telefonu (Android
 * `location`, iPhone `com.apple.quicktime.location.ISO6709`). Kopie
 * vytvořené dřív ji nesou dál — a kopie je to, co se přehrává.
 *
 * Bez `--provest` jen spočítá, kolik kopií polohu nese. S ním je přebalí
 * bez překódování (`-c copy`, obraz ani zvuk se nemění) a vymění soubor
 * přejmenováním ve stejném adresáři. Originály se nikdy nemění — jsou to
 * sama videa dvojice. Opakované spuštění je neškodné: kopie bez polohy se
 * přeskočí.
 */
class VideaBezPolohyCommand extends Command
{
    protected $signature = 'gallery:videa-bez-polohy
        {--provest : Kopie s polohou opravdu přebalí (jinak je jen spočítá)}';

    protected $description = 'Najde kopie videí k přehrávání, které nesou polohu z telefonu, a s --provest ji odstraní';

    /** Jen místní disky — soubor musí jít přebalit a přejmenovat na místě. */
    private const MISTNI = ['public', 'local'];

    private const DAVKA = 200;

    public function handle(VideoProcessingService $videa): int
    {
        if (! $videa->isAvailable()) {
            $this->error('ffmpeg nebo ffprobe nejsou dostupné — zkontrolujte FFMPEG_PATH a FFPROBE_PATH.');

            return self::FAILURE;
        }

        $provest = (bool) $this->option('provest');
        $pocty = ['proslo' => 0, 'poloha' => 0, 'opraveno' => 0, 'chybi' => 0];
        $selhani = [];

        $this->kopie()->chunkById(self::DAVKA, function (Collection $davka) use ($videa, $provest, &$pocty, &$selhani) {
            foreach ($davka as $kopie) {
                $this->zpracuj($videa, $kopie, $provest, $pocty, $selhani);
            }
        }, 'media_variants.id', 'id');

        $this->info("Kopií s polohou: {$pocty['poloha']} z {$pocty['proslo']}.");
        if ($pocty['chybi'] > 0) {
            $this->line("Chybí soubor: {$pocty['chybi']} (přeskočeno).");
        }
        foreach ($selhani as $radek) {
            $this->error($radek);
        }

        if ($provest) {
            $this->info("Poloha odstraněna: {$pocty['opraveno']}.");
        } elseif ($pocty['poloha'] > 0) {
            $this->line('Spusťte s --provest, ať se poloha z kopií odstraní. Originály zůstanou beze změny.');
        }

        return $selhani === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Kopie k přehrávání nesmazaných videí na místních discích. */
    private function kopie()
    {
        return MediaVariant::query()
            ->select('media_variants.*')
            ->join('media_items', 'media_items.id', '=', 'media_variants.media_item_id')
            ->where('media_variants.type', 'video_compat')
            ->whereIn('media_variants.disk', self::MISTNI)
            ->where('media_items.media_type', 'video')
            ->whereNull('media_items.deleted_at');
    }

    /**
     * @param  array<string, int>  $pocty
     * @param  list<string>  $selhani
     */
    private function zpracuj(VideoProcessingService $videa, MediaVariant $kopie, bool $provest, array &$pocty, array &$selhani): void
    {
        $disk = Storage::disk($kopie->disk);
        if (! $disk->exists($kopie->path)) {
            $pocty['chybi']++;

            return;
        }

        $pocty['proslo']++;
        $cesta = $disk->path($kopie->path);
        $kdo = "media #{$kopie->media_item_id} ({$kopie->path})";

        $poloha = $videa->nesePolohu($cesta);
        if ($poloha === null) {
            $selhani[] = "Nešlo zjistit, zda kopie nese polohu: {$kdo}";

            return;
        }
        if (! $poloha) {
            return;
        }

        $pocty['poloha']++;
        if (! $provest) {
            $this->line("S polohou: {$kdo}");

            return;
        }

        if (! $videa->odstranPolohu($cesta)) {
            $selhani[] = "Polohu nešlo odstranit: {$kdo}";

            return;
        }

        clearstatcache(true, $cesta);
        $kopie->update(['size_bytes' => filesize($cesta) ?: $kopie->size_bytes]);
        $pocty['opraveno']++;
    }
}
