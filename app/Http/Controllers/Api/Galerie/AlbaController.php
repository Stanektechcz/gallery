<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Jobs\Drive\CreateDriveFolderJob;
use App\Jobs\Drive\MoveDriveFolderJob;
use App\Jobs\Drive\RenameDriveFolderJob;
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
            'zprava' => 'Album „'.($album->full_display_path ?: $album->title).'“ vytvořeno',
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
            'zprava' => 'Do alba „'.$album->title.'“ zařazeno: '.$pocet,
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /*
     * ——— Správa alba z jeho panelu ———
     *
     * Přejmenování, přesun, koš, titulní fotka i sloučení se v prototypu
     * zapisovaly jen do stavu prohlížeče (`albName`, `albArchived`, …). Album
     * v databázi, složka na Google Disku, sdílený odkaz i staré rozhraní o tom
     * nevěděly — „Album přesunuto" nepřesunulo nic.
     */

    /** Název, místo, datum a popis alba. Nový název přejmenuje i složku na Disku. */
    public function update(Request $request, string $album): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'min:2', 'max:160'],
            'misto' => ['nullable', 'string', 'max:160'],
            'datum' => ['nullable', 'date'],
            'popis' => ['nullable', 'string', 'max:5000'],
        ]);

        $radek = $this->vProstoru($prostor)->where('uuid', $album)->firstOrFail();
        $nazev = trim($data['nazev']);

        if ($nazev !== $radek->title) {
            $radek->update(['sync_status' => 'pending']);

            try {
                RenameDriveFolderJob::dispatch($radek, $nazev);
            } catch (\Throwable $e) {
                Log::warning('Přejmenování složky alba na Disku se nepodařilo zařadit', ['album' => $radek->id, 'chyba' => $e->getMessage()]);
            }
        }

        // Jen pole, která přišla: neposlané datum (rozsah, který klient neumí
        // přečíst) nesmí smazat to uložené.
        $zmeny = ['title' => $nazev];
        foreach (['misto' => 'location_name', 'datum' => 'event_date_start', 'popis' => 'description'] as $pole => $sloupec) {
            if ($request->exists($pole)) {
                $zmeny[$sloupec] = $data[$pole] ?? null;
            }
        }

        $this->alba->update($radek, $zmeny, $request->user());

        AuditLog::record('album.update', $radek, ['title' => $nazev]);

        return response()->json(['ok' => true, 'zprava' => 'Údaje alba uloženy'] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /** Přesun pod jiné album, nebo mezi hlavní alba (`rodic` prázdný). */
    public function presun(Request $request, string $album): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate(['rodic' => ['nullable', 'uuid']]);

        $radek = $this->vProstoru($prostor)->where('uuid', $album)->firstOrFail();
        $rodic = empty($data['rodic']) ? null : $this->vProstoru($prostor)->where('uuid', $data['rodic'])->first();

        if (! empty($data['rodic']) && $rodic === null) {
            return response()->json(['ok' => false, 'zprava' => 'Cílové album už neexistuje.'], 422);
        }

        try {
            $radek->moveTo($rodic?->id);
        } catch (\InvalidArgumentException) {
            return response()->json(['ok' => false, 'zprava' => 'Album nejde vložit do sebe ani do svého podalba.'], 422);
        }

        try {
            MoveDriveFolderJob::dispatch($radek, $rodic?->drive_folder_id);
        } catch (\Throwable $e) {
            Log::warning('Přesun složky alba na Disku se nepodařilo zařadit', ['album' => $radek->id, 'chyba' => $e->getMessage()]);
        }

        AuditLog::record('album.move', $radek, ['rodic' => $rodic?->uuid]);

        return response()->json([
            'ok' => true,
            'zprava' => 'Album přesunuto'.($rodic ? ' do „'.$rodic->title.'“' : ' mezi hlavní alba'),
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /** Titulní fotka — jen fotka z téhož prostoru, ne z koše ani z trezoru. */
    public function titulni(Request $request, string $album): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate(['foto' => ['required', 'uuid']]);

        $radek = $this->vProstoru($prostor)->where('uuid', $album)->firstOrFail();
        $foto = $this->media($prostor, [$data['foto']])->whereNull('trashed_at')->where('is_hidden', false)->first();

        if ($foto === null) {
            return response()->json(['ok' => false, 'zprava' => 'Tahle fotka titulní být nemůže.'], 422);
        }

        DB::transaction(function () use ($radek, $foto) {
            $radek->update(['cover_media_id' => $foto->id]);
            DB::table('album_media')->where('album_id', $radek->id)->update(['is_cover' => false]);
            DB::table('album_media')->where('album_id', $radek->id)->where('media_item_id', $foto->id)->update(['is_cover' => true]);
        });

        return response()->json(['ok' => true, 'zprava' => 'Titulní fotka alba změněna'] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * Album pryč, fotky zůstávají v knihovně.
     *
     * Smazání je měkké a jde vrátit (`obnovit`). Album s podalby se nesmaže:
     * podalba by zůstala viset pod albem, které není vidět.
     */
    public function destroy(Request $request, string $album): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->vProstoru($prostor)->where('uuid', $album)->firstOrFail();

        if ($this->vProstoru($prostor)->where('parent_id', $radek->id)->exists()) {
            return response()->json(['ok' => false, 'zprava' => 'Album má podalba — nejdřív je přesuňte nebo smažte.'], 422);
        }

        $this->alba->softDelete($radek, $request->user());

        return response()->json(['ok' => true, 'zprava' => 'Album smazáno — fotky zůstaly v knihovně'] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    public function obnov(Request $request, string $album): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = Album::withoutGlobalScope(SpaceContext::SCOPE)->withTrashed()
            ->where('gallery_space_id', $prostor->id)->where('uuid', $album)->firstOrFail();

        $radek->restore();
        AuditLog::record('album.restore', $radek);

        return response()->json(['ok' => true, 'zprava' => 'Album vráceno'] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /** Sloučit do jiného alba: fotky se přesunou, prázdné album zmizí. */
    public function sluc(Request $request, string $album): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate(['do' => ['required', 'uuid']]);

        $zdroj = $this->vProstoru($prostor)->where('uuid', $album)->firstOrFail();
        $cil = $this->vProstoru($prostor)->where('uuid', $data['do'])->first();

        if ($cil === null || $cil->id === $zdroj->id) {
            return response()->json(['ok' => false, 'zprava' => 'Cílové album neexistuje.'], 422);
        }

        if ($this->vProstoru($prostor)->where('parent_id', $zdroj->id)->exists()) {
            return response()->json(['ok' => false, 'zprava' => 'Album má podalba — nejdřív je přesuňte.'], 422);
        }

        $pocet = DB::transaction(function () use ($zdroj, $cil, $request) {
            $fotky = DB::table('album_media')->where('album_id', $zdroj->id)->pluck('media_item_id');
            $poradi = (int) DB::table('album_media')->where('album_id', $cil->id)->max('sort_order');

            DB::table('album_media')->insertOrIgnore($fotky->values()->map(fn ($id, $i) => [
                'album_id' => $cil->id, 'media_item_id' => $id, 'sort_order' => $poradi + $i + 1,
                'is_cover' => false, 'added_at' => now(), 'added_by' => $request->user()->id,
            ])->all());

            MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->where('primary_album_id', $zdroj->id)->update(['primary_album_id' => $cil->id]);
            DB::table('album_media')->where('album_id', $zdroj->id)->delete();

            $this->alba->softDelete($zdroj, $request->user());
            $this->prepocitej([$cil->id, $zdroj->id]);

            return $fotky->count();
        });

        return response()->json([
            'ok' => true,
            'zprava' => 'Sloučeno do „'.$cil->title.'“ · přesunuto '.$pocet,
            'album' => $cil->uuid,
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
