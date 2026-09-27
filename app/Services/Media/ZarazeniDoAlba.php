<?php

namespace App\Services\Media;

use App\Jobs\Media\ObnovHledaniJob;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Fotky do alba: členství ve spojovací tabulce a album jako hlavní.
 *
 * Žilo to v `AlbaController` („Zařadit do alba" z mřížky). Nahrávání
 * z telefonu rovnou do alba potřebuje totéž pro každou dokončenou fotku —
 * druhá kopie by se časem rozešla (počet v albu, hledání podle alba).
 */
class ZarazeniDoAlba
{
    /**
     * Album prostoru, do kterého se smí zařazovat — nesmazané a vlastní.
     */
    public function najdi(GallerySpace|int $prostor, string $uuid): ?Album
    {
        $id = $prostor instanceof GallerySpace ? $prostor->id : $prostor;

        return Album::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $id)
            ->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Hlavní album (`primary_album_id`) čte knihovna i složka na Disku; jen
     * spojovací řádek by fotku v albu ukázal, ale na obrazovce u ní dál stálo
     * staré album.
     *
     * @param  list<string>  $uuid
     */
    public function zarad(Album $album, GallerySpace|int $prostor, array $uuid, int $kdo): int
    {
        if ($uuid === []) {
            return 0;
        }

        $prostorId = $prostor instanceof GallerySpace ? $prostor->id : $prostor;

        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostorId)
            ->whereIn('uuid', $uuid)
            ->get(['id']);

        $poradi = (int) DB::table('album_media')->where('album_id', $album->id)->max('sort_order');

        DB::table('album_media')->insertOrIgnore($media->values()->map(fn ($m, $i) => [
            'album_id' => $album->id,
            'media_item_id' => $m->id,
            'sort_order' => $poradi + $i + 1,
            'is_cover' => false,
            'added_at' => now(),
            'added_by' => $kdo,
        ])->all());

        // Alba, ze kterých fotky odcházejí jako z hlavního, přijdou o položku v počtu.
        $puvodni = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereIn('id', $media->pluck('id'))->whereNotNull('primary_album_id')
            ->pluck('primary_album_id')->all();

        MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereIn('id', $media->pluck('id'))
            ->update(['primary_album_id' => $album->id]);

        $this->prepocitej(array_merge($puvodni, [$album->id]));

        // Nové album (a u hlavního i jeho cesta) patří do `search_text`. Úloha
        // počká na potvrzení transakce, ve které se zařazuje.
        ObnovHledaniJob::zkusNaplanovat($media->pluck('id'), 'zařazení do alba');

        return $media->count();
    }

    /**
     * Počet položek v albech (`albums.media_count`).
     *
     * Knihovna ho čte ze sloupce; bez přepočtu stálo u alba s právě zařazenou
     * fotkou „0 položek".
     *
     * @param  list<int>  $alba
     */
    public function prepocitej(array $alba): void
    {
        foreach (array_unique(array_map('intval', $alba)) as $id) {
            // Stejné členství jako archiv a sdílená stránka: spojovací tabulka
            // i `primary_album_id` (hromadné „Přesunout", import). Každá fotka
            // jednou, i když je zařazená oběma cestami.
            $pocet = DB::table('media_items as m')
                ->whereNull('m.trashed_at')
                ->where(fn ($q) => $q->where('m.primary_album_id', $id)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')
                        ->from('album_media as am')
                        ->whereColumn('am.media_item_id', 'm.id')
                        ->where('am.album_id', $id)))
                ->count();

            DB::table('albums')->where('id', $id)->update(['media_count' => $pocet]);
        }
    }
}
