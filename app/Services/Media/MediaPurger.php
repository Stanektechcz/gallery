<?php

namespace App\Services\Media;

use App\Models\CloudCopyDeletion;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Trvalé odstranění jedné položky — soubory, náhledy a kopie v cloudu.
 *
 * Jedna implementace pro všechna rozhraní. Dvě by znamenaly dvě místa, kde se dá
 * zapomenout smazat originál z Google Disku, a fotka, o které aplikace tvrdí,
 * že je pryč, by dál ležela v cizím cloudu.
 *
 * Kopie v cloudu (originál na Disku i zrcadlo v Dropboxu, OneDrivu a WebDAV)
 * se tu jen **zaznamenají** do `cloud_copy_deletions` a smaže je úloha
 * `RemoveCloudCopy`. Záznam musí vzniknout před smazáním řádku: varianty
 * `cloud_copy` odcházejí kaskádou s ním a odkaz na vzdálený soubor by zmizel
 * navždy. Mazání u nás na cloudu nečeká a jeho výpadkem nepadá — koš, který
 * nejde vyprázdnit, protože Dropbox má špatnou minutu, by byl horší.
 *
 * Záznamy zakládá `KopieVCloudu` — stejná třída jako při přesunu do trezoru,
 * aby se výběr spojení na dvou místech nerozešel.
 */
class MediaPurger
{
    public function __construct(private readonly KopieVCloudu $kopie) {}

    public function purge(MediaItem $media): void
    {
        // Jako první, dokud varianty ještě jsou. Chyba databáze tu propadne
        // volajícímu schválně: řádek pak zůstane v koši a smazání půjde zopakovat,
        // místo aby odešel a odkaz na kopii v cloudu s ním.
        $zaznamy = $this->kopie->zaznamenej($media, CloudCopyDeletion::DUVOD_SMAZANI);

        $this->smazNahledy($media);
        $this->smazSlozky($media);

        $this->kopie->zaradPoPotvrzeni($zaznamy);
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
}
