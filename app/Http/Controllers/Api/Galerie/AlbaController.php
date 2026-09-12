<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\AlbumService;
use App\Services\Obsah\Knihovna;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alba z prototypu: založit album a zařadit do něj fotky.
 *
 * „Vytvořit album" i hromadné „Zařadit do albumu" dosud zapsaly jen do stavu
 * v prohlížeči (`myAlbums`, `edits[id].album`). Hláška „Album vytvořeno" tak
 * nevytvořila nic, co by znal zbytek aplikace: album nebylo v databázi, nemělo
 * složku na Disku a fotky v něm neležely nikde jinde než na jedné obrazovce.
 *
 * Zakládá se přes tutéž službu jako ve druhém rozhraní (cesty ve stromu,
 * protokol) a složka na Disku se zařadí do fronty stejně.
 */
class AlbaController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Kolik fotek jde zařadit jedním požadavkem — výběr v mřížce víc nepojme. */
    private const NAJEDNOU = 2000;

    public function __construct(
        private readonly AlbumService $alba,
        private readonly Knihovna $obsah,
    ) {}

    /** Nové album, případně rovnou s vybranými fotkami. */
    public function store(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'min:2', 'max:160'],
            'rodic' => ['nullable', 'uuid'],
            'media' => ['nullable', 'array', 'max:'.self::NAJEDNOU],
            'media.*' => ['uuid'],
        ]);

        $rodic = null;

        if (! empty($data['rodic'])) {
            $rodic = $this->vProstoru($prostor)->where('uuid', $data['rodic'])->first();

            if ($rodic === null) {
                return response()->json(['ok' => false, 'zprava' => 'Nadřazené album už neexistuje.'], 422);
            }
        }

        $album = DB::transaction(function () use ($prostor, $data, $rodic, $request) {
            $album = $this->alba->create($prostor, [
                'title' => trim($data['nazev']),
                'parent_id' => $rodic?->id,
                // Prototyp je pro dva: album založené v něm vidí oba.
                'visibility' => 'shared',
            ], $request->user());

            $this->zaradDo($album, $prostor, $data['media'] ?? [], $request->user()->id);

            return $album;
        });

        $this->slozkaNaDisku($album);

        return response()->json([
            'ok' => true,
            'album' => $album->uuid,
            'zprava' => 'Album „'.($album->full_display_path ?: $album->title).'" vytvořeno',
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * Zařadit vybrané fotky do alba, nebo je z alb vyjmout (`album` prázdné).
     */
    public function zarad(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'album' => ['nullable', 'uuid'],
            'media' => ['required', 'array', 'min:1', 'max:'.self::NAJEDNOU],
            'media.*' => ['uuid'],
        ]);

        if (empty($data['album'])) {
            $id = $this->media($prostor, $data['media'])->pluck('id');

            DB::transaction(function () use ($id) {
                $dotcena = DB::table('album_media')->whereIn('media_item_id', $id)->pluck('album_id')
                    ->merge(MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->whereIn('id', $id)->whereNotNull('primary_album_id')->pluck('primary_album_id'))
                    ->unique()->all();

                DB::table('album_media')->whereIn('media_item_id', $id)->delete();
                MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->whereIn('id', $id)->update(['primary_album_id' => null]);
                $this->prepocitej($dotcena);
            });

            AuditLog::record('album.media_removed', null, ['count' => $id->count()]);

            return response()->json([
                'ok' => true,
                'zprava' => 'Vyjmuto z alb: '.$id->count(),
            ] + $this->obsahPoAkci($this->obsah, $prostor));
        }

        $album = $this->vProstoru($prostor)->where('uuid', $data['album'])->first();

        if ($album === null) {
            return response()->json(['ok' => false, 'zprava' => 'Album už neexistuje.'], 404);
        }

        $pocet = DB::transaction(fn () => $this->zaradDo($album, $prostor, $data['media'], $request->user()->id));

        return response()->json([
            'ok' => true,
            'zprava' => 'Do alba „'.$album->title.'" zařazeno: '.$pocet,
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * Fotky do alba: členství ve spojovací tabulce a album jako hlavní.
     *
     * Hlavní album (`primary_album_id`) čte knihovna i složka na Disku; jen
     * spojovací řádek by fotku v albu ukázal, ale na obrazovce u ní dál stálo
     * staré album.
     *
     * @param  list<string>  $uuid
     */
    private function zaradDo(Album $album, GallerySpace $prostor, array $uuid, int $kdo): int
    {
        if ($uuid === []) {
            return 0;
        }

        $media = $this->media($prostor, $uuid)->get(['id']);
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
    private function prepocitej(array $alba): void
    {
        foreach (array_unique(array_map('intval', $alba)) as $id) {
            $pocet = DB::table('album_media as am')
                ->join('media_items as m', 'm.id', '=', 'am.media_item_id')
                ->where('am.album_id', $id)
                ->whereNull('m.trashed_at')
                ->count();

            DB::table('albums')->where('id', $id)->update(['media_count' => $pocet]);
        }
    }

    private function vProstoru(GallerySpace $prostor)
    {
        return Album::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at');
    }

    /** @param  list<string>  $uuid */
    private function media(GallerySpace $prostor, array $uuid)
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('uuid', $uuid);
    }

    /** Složka na Disku; když fronta nejede, album stejně vznikne a složku dožene synchronizace. */
    private function slozkaNaDisku(Album $album): void
    {
        try {
            CreateDriveFolderJob::dispatch($album);
        } catch (\Throwable $e) {
            Log::warning('Složku alba na Disku se nepodařilo zařadit', ['album' => $album->id, 'chyba' => $e->getMessage()]);
        }
    }
}
