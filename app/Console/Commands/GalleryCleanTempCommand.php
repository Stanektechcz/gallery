<?php

namespace App\Console\Commands;

use App\Models\GuestUpload;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class GalleryCleanTempCommand extends Command
{
    protected $signature = 'gallery:clean-temp {--older-than=7 : Delete temp files older than X days}';

    protected $description = 'Clean up temporary upload files and expired export files';

    /** Soubory ze sdílení (PWA Share Target) čekají na výběr alba jen krátce. */
    private const SDILENI_PO_HODINACH = 24;

    /** Nedokončená nahrávání z prototypu — viz Api\Galerie\MediaController. */
    private const CASTI_Z_PROTOTYPU = 'upload_chunks/galerie';

    /*
     * Kde co doopravdy leží (ověřeno proti místům, která zapisují):
     *
     * - části nahrávek: disk `local` (kořen `storage/app/private`) —
     *   `upload_chunks/{uuid}` z UploadControlleru a `upload_chunks/galerie/{uživatel}-{id}`
     *   z prototypu. Úklid dřív hledal v `storage/app/upload_chunks`, kde nic není.
     * - sdílení: disk `local`, `share_target/…` (`store('share_target', 'local')`).
     * - složené nahrávky: přímo `storage/app/uploads/{uuid}/…` (storage_path, ne disk).
     * - exporty: přímo `storage/app/exports/{id}.zip` (GenerateExportJob, ExportController).
     *
     * `storage/app/imports` se neuklízí záměrně: `gallery:import` tam nechává
     * jedinou kopii souboru, bez varianty `original`.
     */
    public function handle(): int
    {
        $days = max(0, (int) $this->option('older-than'));
        $cutoff = now()->subDays($days)->getTimestamp();
        $disk = Storage::disk('local');

        $cleaned = $this->castiNahravek($disk, $cutoff)
            + $this->souboryZeSdileni($disk, now()->subHours(self::SDILENI_PO_HODINACH)->getTimestamp())
            + $this->slozeneNahravky($cutoff)
            + $this->stareExporty($cutoff)
            + $this->osireleNahravkyHostu();

        $this->info("Cleaned {$cleaned} temporary files/directories.");

        return Command::SUCCESS;
    }

    /**
     * Složky s částmi nahrávek, do kterých od `$cutoff` nic nepřibylo.
     *
     * Celé složky a rekurzivně: dřívější smyčka volala `unlink` na všechno ve
     * složce, takže by na podsložce (`galerie/…`) spadla.
     */
    private function castiNahravek(Filesystem $disk, int $cutoff): int
    {
        $slozky = collect($disk->directories('upload_chunks'))
            ->reject(fn (string $slozka) => $slozka === self::CASTI_Z_PROTOTYPU)
            ->merge($disk->directories(self::CASTI_Z_PROTOTYPU));
        $smazano = 0;

        foreach ($slozky as $slozka) {
            if ($this->slozkaJeCerstva($disk, $slozka, $cutoff)) {
                continue;
            }

            $disk->deleteDirectory($slozka);
            $smazano++;
        }

        return $smazano;
    }

    private function souboryZeSdileni(Filesystem $disk, int $cutoff): int
    {
        $smazano = 0;

        foreach ($disk->files('share_target') as $soubor) {
            if ($this->zmenenoPo($disk, $soubor, $cutoff)) {
                continue;
            }

            $disk->delete($soubor);
            $smazano++;
        }

        return $smazano;
    }

    /** Dočasné složení z UploadControlleru; úlohy pak sáhnou po uloženém originálu. */
    private function slozeneNahravky(int $cutoff): int
    {
        $koren = storage_path('app/uploads');
        $smazano = 0;

        if (! File::isDirectory($koren)) {
            return 0;
        }

        foreach (File::directories($koren) as $slozka) {
            $cerstva = collect(File::allFiles($slozka))->contains(fn ($soubor) => $soubor->getMTime() >= $cutoff);

            if ($cerstva) {
                continue;
            }

            File::deleteDirectory($slozka);
            $smazano++;
        }

        return $smazano;
    }

    private function stareExporty(int $cutoff): int
    {
        $smazano = 0;

        foreach (File::glob(storage_path('app/exports').'/*.zip') as $soubor) {
            if (File::lastModified($soubor) >= $cutoff) {
                continue;
            }

            File::delete($soubor);
            $smazano++;
        }

        return $smazano;
    }

    /** Složka, do které od `$cutoff` něco přibylo — nahrávání možná ještě běží. */
    private function slozkaJeCerstva(Filesystem $disk, string $slozka, int $cutoff): bool
    {
        return collect($disk->allFiles($slozka))->contains(fn (string $soubor) => $this->zmenenoPo($disk, $soubor, $cutoff));
    }

    /**
     * Soubor mezi výpisem a dotazem mohl zmizet (nahrávání se právě složilo).
     * Pak se bere jako čerstvý — úklid nesmí spadnout ani mazat naslepo.
     */
    private function zmenenoPo(Filesystem $disk, string $soubor, int $cutoff): bool
    {
        try {
            return $disk->lastModified($soubor) >= $cutoff;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Soubory od hostů, ke kterým už nepatří žádná čekající nahrávka.
     *
     * Smazaný odkaz dřív vzal řádky nahrávek (`cascadeOnDelete`), ale ne
     * soubory — ty ležely na disku napořád. Teď je smaže sám odkaz; tohle
     * dočistí, co zbylo z dřívějška, a prázdné složky po schválených.
     *
     * Čerstvá složka zůstává: soubor se ukládá dřív než řádek, takže složka
     * bez řádku může být nahrávka, která právě probíhá.
     */
    private function osireleNahravkyHostu(): int
    {
        $disk = Storage::disk('local');
        $cekajici = GuestUpload::where('status', 'pending')
            ->pluck('storage_path')
            ->map(fn (string $cesta) => dirname($cesta))
            ->flip();
        $vcera = now()->subDay()->getTimestamp();
        $smazano = 0;

        foreach ($disk->directories('guest_uploads') as $slozka) {
            // Jen složky přesně toho tvaru, který nahrávání zakládá.
            if (isset($cekajici[$slozka]) || ! preg_match('#^guest_uploads/[0-9a-f-]{36}$#i', $slozka)) {
                continue;
            }

            $cerstva = collect($disk->allFiles($slozka))->contains(fn (string $soubor) => $disk->lastModified($soubor) > $vcera);

            if ($cerstva) {
                continue;
            }

            $disk->deleteDirectory($slozka);
            $smazano++;
        }

        return $smazano;
    }
}
