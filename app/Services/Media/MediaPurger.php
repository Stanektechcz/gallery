<?php

namespace App\Services\Media;

use App\Jobs\Media\RemoveCloudCopy;
use App\Models\CloudCopyDeletion;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Storage\DriveConnectionResolver;
use Illuminate\Support\Facades\DB;
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
 */
class MediaPurger
{
    public function __construct(private readonly DriveConnectionResolver $disky) {}

    public function purge(MediaItem $media): void
    {
        // Jako první, dokud varianty ještě jsou. Chyba databáze tu propadne
        // volajícímu schválně: řádek pak zůstane v koši a smazání půjde zopakovat,
        // místo aby odešel a odkaz na kopii v cloudu s ním.
        $zaznamy = $this->zaznamenejKopieVCloudu($media);

        $this->smazNahledy($media);
        $this->smazSlozky($media);

        $this->zaradMazani($zaznamy);
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

    /**
     * Jeden záznam za originál na Disku a jeden za každou kopii `cloud_copy`.
     *
     * Bez jména souboru — záznam přežije položku a nesmí prozradit, co bylo
     * v trezoru.
     *
     * @return list<int> id založených záznamů
     */
    private function zaznamenejKopieVCloudu(MediaItem $media): array
    {
        $kopie = [];

        if ($media->drive_file_id) {
            $kopie[] = ['provider' => 'google_drive', 'ref' => (string) $media->drive_file_id];
        }

        foreach ($media->variants as $varianta) {
            if ($varianta->type !== 'cloud_copy' || ! $varianta->path || $varianta->disk === 'public') {
                continue;
            }

            $kopie[] = ['provider' => (string) $varianta->disk, 'ref' => (string) $varianta->path];
        }

        $ids = [];

        foreach ($kopie as $jedna) {
            $ids[] = CloudCopyDeletion::create([
                'gallery_space_id' => $media->gallery_space_id,
                'storage_connection_id' => $this->spojeni($media, $jedna['provider'])?->id,
                'provider' => $jedna['provider'],
                'remote_ref' => $jedna['ref'],
                'media_uuid' => $media->uuid,
                'reason' => 'purge',
            ])->id;
        }

        return $ids;
    }

    /**
     * Spojení prostoru položky, ne kteréhokoli prostoru jejího vlastníka.
     *
     * Dropbox, OneDrive a WebDAV patří prostoru (`gallery_space_id`), stejně
     * jako je vybírá `StorageResolver`. Google Disk patří účtu a sloupec prostoru
     * u něj po připojení prázdný bývá — najde se proto přes dvojici prostoru,
     * stejně jako při nahrávání.
     */
    private function spojeni(MediaItem $media, string $poskytovatel): ?StorageConnection
    {
        $spojeni = StorageConnection::where('gallery_space_id', $media->gallery_space_id)
            ->where('provider', $poskytovatel)
            ->orderByRaw('CASE WHEN connection_status = ? THEN 0 ELSE 1 END', [StorageConnection::STATUS_HEALTHY])
            ->first();

        if ($spojeni || $poskytovatel !== 'google_drive') {
            return $spojeni;
        }

        return $this->disky->forPurge((int) $media->gallery_space_id, $media->owner_user_id);
    }

    /**
     * Úlohy až po potvrzení transakce — worker by jinak mohl hledat záznam,
     * který ještě neexistuje, nebo mazat kopii položky, jejíž smazání se vrátilo.
     *
     * Každé zařazení zvlášť a bez výjimky (`zaradBezpecne`), stejně jako
     * u zrcadlení v nahrávání: na synchronní frontě úloha běží přímo tady
     * a výpadek cloudu by jinak shodil mazání u nás. Záznam zůstane a doktor
     * ho ukáže.
     *
     * @param  list<int>  $ids
     */
    private function zaradMazani(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::afterCommit(function () use ($ids) {
            foreach ($ids as $id) {
                RemoveCloudCopy::zaradBezpecne($id);
            }
        });
    }
}
