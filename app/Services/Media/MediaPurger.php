<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Trvalé odstranění jedné položky — soubory, náhledy a kopie na Disku.
 *
 * Jedna implementace pro obě rozhraní. Dvě by znamenaly dvě místa, kde se dá
 * zapomenout smazat originál z Google Disku, a fotka, o které aplikace tvrdí,
 * že je pryč, by dál ležela v cizím cloudu.
 *
 * Mazání na Disku je **best-effort**: když účet zrovna neodpovídá, záznam se
 * v aplikaci odstranit má. Zůstat by znamenalo koš, který nejde vyprázdnit.
 */
class MediaPurger
{
    public function purge(MediaItem $media): void
    {
        $this->smazNahledy($media);
        $this->smazSlozky($media);
        $this->smazNaDisku($media);
    }

    private function smazNahledy(MediaItem $media): void
    {
        foreach ($media->variants as $varianta) {
            if ($varianta->disk !== 'public' || ! $varianta->path) {
                continue;
            }

            try {
                Storage::disk('public')->delete($varianta->path);
            } catch (\Throwable $e) {
                Log::warning('Could not delete variant file', ['path' => $varianta->path, 'error' => $e->getMessage()]);
            }
        }
    }

    private function smazSlozky(MediaItem $media): void
    {
        try {
            Storage::disk('public')->deleteDirectory("media/{$media->uuid}");
        } catch (\Throwable $e) {
            Log::warning('Could not delete media directory', ['uuid' => $media->uuid]);
        }

        // Sesbíraný soubor z částí nahrávání. Zůstal by na disku serveru
        // i po smazání položky a nikdo by o něm nevěděl.
        $slozka = storage_path("app/uploads/{$media->uuid}");

        if (is_dir($slozka)) {
            array_map('unlink', glob("$slozka/*") ?: []);
            @rmdir($slozka);
        }
    }

    private function smazNaDisku(MediaItem $media): void
    {
        if (! $media->drive_file_id) {
            return;
        }

        try {
            $spojeni = StorageConnection::whereHas(
                'owner',
                fn ($q) => $q->whereHas('gallerySpaces', fn ($q2) => $q2->where('gallery_spaces.id', $media->gallery_space_id)),
            )->where('provider', 'google_drive')->where('connection_status', 'healthy')->first();

            if ($spojeni) {
                (new GoogleDriveStorageProvider($spojeni))->trash($media->drive_file_id);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not trash Drive file', ['drive_id' => $media->drive_file_id, 'error' => $e->getMessage()]);
        }
    }
}
