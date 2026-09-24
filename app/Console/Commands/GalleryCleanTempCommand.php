<?php

namespace App\Console\Commands;

use App\Models\GuestUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GalleryCleanTempCommand extends Command
{
    protected $signature = 'gallery:clean-temp {--older-than=7 : Delete temp files older than X days}';

    protected $description = 'Clean up temporary upload files and expired export files';

    public function handle(): int
    {
        $days = (int) $this->option('older-than');
        $cutoff = now()->subDays($days)->timestamp;
        $cleaned = 0;

        // Clean old upload chunks
        $chunkDir = storage_path('app/upload_chunks');
        if (is_dir($chunkDir)) {
            foreach (glob($chunkDir.'/*', GLOB_ONLYDIR) as $sessionDir) {
                if (filemtime($sessionDir) < $cutoff) {
                    array_map('unlink', glob($sessionDir.'/*'));
                    @rmdir($sessionDir);
                    $cleaned++;
                }
            }
        }

        // Clean assembled uploads that are no longer needed
        $uploadsDir = storage_path('app/uploads');
        if (is_dir($uploadsDir)) {
            foreach (glob($uploadsDir.'/*/*') as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        // Clean old exports
        $exportsDir = storage_path('app/exports');
        if (is_dir($exportsDir)) {
            foreach (glob($exportsDir.'/*.zip') as $file) {
                if (filemtime($file) < $cutoff) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        // Clean share target temp files
        $shareDir = storage_path('app/share_target');
        if (is_dir($shareDir)) {
            foreach (glob($shareDir.'/*') as $file) {
                if (is_file($file) && filemtime($file) < now()->subHours(24)->timestamp) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        $cleaned += $this->osireleNahravkyHostu();

        $this->info("Cleaned {$cleaned} temporary files/directories.");

        return Command::SUCCESS;
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
