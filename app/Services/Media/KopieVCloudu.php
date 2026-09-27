<?php

namespace App\Services\Media;

use App\Jobs\Media\RemoveCloudCopy;
use App\Models\CloudCopyDeletion;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Storage\DriveConnectionResolver;
use Illuminate\Support\Facades\DB;

/**
 * Záznamy o kopiích v cloudu, které mají zmizet.
 *
 * Jedno místo pro trvalé smazání (`MediaPurger`) i pro trezor
 * (`OdeberKopieVTrezoru`, zrcadlení a nahrávání na Disk, které doběhlo až po
 * přesunu do trezoru). Dvě kopie téhož kódu by se rozešly ve výběru spojení
 * — a kopie by se pak mazala cizím účtem, nebo vůbec.
 *
 * Záznam nenese jméno souboru: přežije položku a nesmí prozradit, co bylo
 * v trezoru.
 */
class KopieVCloudu
{
    public function __construct(private readonly DriveConnectionResolver $disky) {}

    /**
     * Jeden záznam za originál na Disku a jeden za každou kopii `cloud_copy`.
     *
     * @return list<int> id založených záznamů
     */
    public function zaznamenej(MediaItem $media, string $duvod): array
    {
        $ids = [];

        if ($media->drive_file_id) {
            $ids[] = $this->zaznamenejJednu($media, 'google_drive', (string) $media->drive_file_id, $duvod);
        }

        foreach ($media->variants()->where('type', 'cloud_copy')->get() as $varianta) {
            if (! $varianta->path || $varianta->disk === 'public') {
                continue;
            }

            $ids[] = $this->zaznamenejJednu($media, (string) $varianta->disk, (string) $varianta->path, $duvod);
        }

        return $ids;
    }

    /** Jedna konkrétní kopie — třeba soubor, který cloud vrátil až po přesunu do trezoru. */
    public function zaznamenejJednu(MediaItem $media, string $poskytovatel, string $odkaz, string $duvod): int
    {
        return CloudCopyDeletion::create([
            'gallery_space_id' => $media->gallery_space_id,
            'storage_connection_id' => $this->spojeni($media, $poskytovatel)?->id,
            'provider' => $poskytovatel,
            'remote_ref' => $odkaz,
            'media_uuid' => $media->uuid,
            'reason' => $duvod,
        ])->id;
    }

    /**
     * Úlohy až po potvrzení transakce — worker by jinak mohl hledat záznam,
     * který ještě neexistuje, nebo mazat kopii, jejíž odebrání se vrátilo.
     *
     * Každé zařazení zvlášť a bez výjimky (`zaradBezpecne`): na synchronní
     * frontě úloha běží přímo tady a výpadek cloudu by jinak shodil volajícího.
     * Záznam zůstane a doktor ho ukáže.
     *
     * @param  list<int>  $ids
     */
    public function zaradPoPotvrzeni(array $ids): void
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
}
