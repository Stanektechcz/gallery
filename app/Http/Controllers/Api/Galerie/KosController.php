<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Media\MediaPurger;
use App\Services\Obsah\System;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Koš prototypu: vrátit, trvale odstranit, vyprázdnit.
 *
 * Obrazovka měla čtyři vymyšlené řádky a tlačítka, která jen přepsala stav
 * v prohlížeči. Potvrzovací dialog přitom sliboval, že se „odstraní i originály
 * z Google Drivu" — a nesmazalo se nic, ani v aplikaci, ani na Disku.
 *
 * Trvalé odstranění se **nedělá potichu**: zapisuje se do protokolu stejně
 * jako ve druhém rozhraní a maže se přes tutéž službu, aby se nedalo
 * zapomenout na kopii v cloudu.
 */
class KosController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(
        private readonly MediaPurger $mazani,
        private readonly System $obsah,
    ) {}

    /** Vrátit z koše — vratná akce, smí ji každý z dvojice. */
    public function restore(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate(['id' => ['required', 'string', 'max:64']]);

        $polozka = $this->vKosi($prostor)->where('uuid', $data['id'])->first();

        if ($polozka === null) {
            return response()->json(['ok' => false, 'zprava' => 'Tahle položka v koši není.'], 404);
        }

        $polozka->update(['trashed_at' => null, 'purge_after' => null]);
        AuditLog::record('media.restore', $polozka);

        return response()->json([
            'ok' => true,
            'zprava' => 'Vráceno z koše — „'.$polozka->original_filename.'“',
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /** Trvale odstranit jednu položku. */
    public function purge(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate(['id' => ['required', 'string', 'max:64']]);

        if (($odmitnuto = $this->smiMazat($request)) !== null) {
            return $odmitnuto;
        }

        $polozka = $this->vKosi($prostor)->where('uuid', $data['id'])->first();

        if ($polozka === null) {
            return response()->json(['ok' => false, 'zprava' => 'Tahle položka v koši není.'], 404);
        }

        $jmeno = $polozka->original_filename;

        AuditLog::record('media.purge', $polozka, ['filename' => $jmeno]);
        $this->mazani->purge($polozka);
        $polozka->delete();

        return response()->json([
            'ok' => true,
            'zprava' => 'Trvale odstraněno — „'.$jmeno.'“',
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /** Vyprázdnit celý koš. */
    public function empty(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        if (($odmitnuto = $this->smiMazat($request)) !== null) {
            return $odmitnuto;
        }

        $polozky = $this->vKosi($prostor)->get();

        foreach ($polozky as $polozka) {
            AuditLog::record('media.purge', $polozka, ['via' => 'empty_trash']);
            $this->mazani->purge($polozka);
            $polozka->delete();
        }

        $kolik = $polozky->count();

        return response()->json([
            'ok' => true,
            'zprava' => $kolik === 0
                ? 'Koš byl prázdný'
                : 'Koš vyprázdněn — '.$kolik.' '.match (true) {
                    $kolik === 1 => 'položka trvale odstraněna',
                    $kolik <= 4 => 'položky trvale odstraněny',
                    default => 'položek trvale odstraněno',
                },
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * Trvalé odstranění je vyhrazené správci — stejně jako ve druhém rozhraní.
     *
     * Odmítnutí se **říká**: tlačítko, které mlčí, vypadá jako rozbité, a
     * u mazání je to ta horší varianta z obou.
     */
    private function smiMazat(Request $request): ?JsonResponse
    {
        if ($request->user()?->isAdmin()) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'zprava' => 'Trvale odstranit smí jen správce prostoru. Do koše to zatím zůstane.',
        ], 403);
    }

    /** @return Builder<MediaItem> */
    private function vKosi(GallerySpace $prostor)
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('trashed_at');
    }
}
