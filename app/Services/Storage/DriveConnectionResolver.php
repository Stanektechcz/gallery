<?php

namespace App\Services\Storage;

use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Auth\PristupDoGalerie;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the usable Drive for a shared gallery space. Media can be uploaded
 * by either partner, while the connected Drive commonly belongs to the space
 * owner; selecting only the uploader's connection left such files local.
 */
class DriveConnectionResolver
{
    public function forMedia(MediaItem $media): ?StorageConnection
    {
        return $this->forSpace($media->gallery_space_id, $media->owner_user_id);
    }

    /**
     * `$preferredUserId` jen řadí — do okruhu dvojice nikoho nepřidává.
     *
     * Dřív se autor připojil k členům bez podmínky, takže nahrávka hosta
     * (přispěvatele) poslala originál na jeho vlastní Disk.
     */
    public function forSpace(int $spaceId, ?int $preferredUserId = null): ?StorageConnection
    {
        $memberIds = $this->dvojice($spaceId);

        if (! $memberIds) {
            return null;
        }

        return StorageConnection::query()
            ->where('provider', 'google_drive')
            ->where('connection_status', 'healthy')
            ->whereNotNull('root_folder_id')
            ->whereIn('owner_user_id', $memberIds)
            ->orderByRaw('CASE WHEN owner_user_id = ? THEN 0 ELSE 1 END', [$preferredUserId ?? 0])
            ->orderByDesc('last_successful_request_at')
            ->first();
    }

    /**
     * Disk, na kterém originál položky nejspíš leží — kvůli jeho smazání.
     *
     * Stejný okruh jako při nahrávání (dvojice prostoru, autor napřed), ale bez
     * podmínky zdravého spojení a kořenové složky. Soubor na Disku zůstává, i když
     * spojení zrovna hlásí chybu; smazání se zaznamená a úloha ho zkusí znovu,
     * až se spojení obnoví. Dřív se zdravé spojení hledalo přes všechny prostory,
     * kde je vlastník členem — i přes Disk hosta — a v chybě se nesmazalo nic.
     */
    public function forPurge(int $spaceId, ?int $preferredUserId = null): ?StorageConnection
    {
        $memberIds = $this->dvojice($spaceId);

        if (! $memberIds) {
            return null;
        }

        return StorageConnection::query()
            ->where('provider', 'google_drive')
            ->whereIn('owner_user_id', $memberIds)
            ->orderByRaw('CASE WHEN owner_user_id = ? THEN 0 ELSE 1 END', [$preferredUserId ?? 0])
            ->orderByRaw('CASE WHEN connection_status = ? THEN 0 ELSE 1 END', [StorageConnection::STATUS_HEALTHY])
            ->orderByDesc('last_successful_request_at')
            ->first();
    }

    /**
     * Účty, jejichž Disk smí nést originály prostoru: vlastník a členové
     * s rolí dvojice.
     *
     * Za člena se dřív bral každý řádek `gallery_space_user`, tedy i host
     * (`viewer`/`contributor`). Host s vlastním připojeným Diskem tak mohl
     * dostávat originály dvojice a panel úložiště ukazoval e-mail jeho účtu.
     * Vlastník se přidává zvlášť — je vlastníkem, i když mu řádek členství chybí
     * nebo v něm zůstala výchozí role (viz `PristupDoGalerie`).
     *
     * @return list<int>
     */
    private function dvojice(int $spaceId): array
    {
        $ids = DB::table('gallery_space_user')
            ->where('gallery_space_id', $spaceId)
            ->whereIn('role', PristupDoGalerie::ROLE_DVOJICE)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $vlastnik = DB::table('gallery_spaces')->where('id', $spaceId)->value('owner_id');

        if ($vlastnik !== null) {
            $ids[] = (int) $vlastnik;
        }

        return array_values(array_unique($ids));
    }
}
