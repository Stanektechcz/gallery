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

    /**
     * Vrátit z koše — vratná akce, smí ji každý z dvojice.
     *
     * Kromě jedné položky (`id`) bere i seznam (`ids`): „Zpět" po hromadném
     * přesunu do koše by jinak poslal požadavek za každou fotku a u větší
     * dávky narazil na limit API.
     */
    public function restore(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        if ($request->has('ids')) {
            $data = $request->validate([
                'ids' => ['required', 'array', 'min:1', 'max:500'],
                'ids.*' => ['string', 'max:64'],
            ]);

            $polozky = $this->vKosi($prostor)->whereIn('uuid', $data['ids'])->get();

            if ($polozky->isEmpty()) {
                return response()->json(['ok' => false, 'zprava' => 'Nic z toho v koši není.'], 404);
            }

            MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                ->whereIn('id', $polozky->pluck('id'))
                ->update(['trashed_at' => null, 'purge_after' => null]);
            $polozky->each(fn (MediaItem $m) => AuditLog::record('media.restore', $m));

            return response()->json([
                'ok' => true,
                'ids' => $polozky->pluck('uuid')->values()->all(),
                'zprava' => 'Vráceno z koše — '.$polozky->count().' '.($polozky->count() === 1 ? 'položka' : ($polozky->count() <= 4 ? 'položky' : 'položek')),
            ] + $this->obsahPoAkci($this->obsah, $prostor));
        }

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

        if (($odmitnuto = $this->smiMazat($request, $prostor)) !== null) {
            return $odmitnuto;
        }

        $polozka = $this->vKosi($prostor)->where('uuid', $data['id'])->first();

        if ($polozka === null) {
            return response()->json(['ok' => false, 'zprava' => 'Tahle položka v koši není.'], 404);
        }

        $jmeno = $polozka->original_filename;

        AuditLog::record('media.purge', $polozka, ['filename' => $jmeno]);
        $this->mazani->purge($polozka);
        // `forceDelete`, ne `delete`: model má soft delete, takže by po
        // „trvale odstraněno" zůstal řádek s `deleted_at` — bez souborů,
        // neviditelný pro koš i pro noční úklid, který se ptá na `trashed_at`.
        $polozka->forceDelete();

        return response()->json([
            'ok' => true,
            'zprava' => 'Trvale odstraněno — „'.$jmeno.'“',
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /** Vyprázdnit celý koš. */
    public function empty(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        if (($odmitnuto = $this->smiMazat($request, $prostor)) !== null) {
            return $odmitnuto;
        }

        $polozky = $this->vKosi($prostor)->get();

        foreach ($polozky as $polozka) {
            AuditLog::record('media.purge', $polozka, ['via' => 'empty_trash']);
            $this->mazani->purge($polozka);
            $polozka->forceDelete();
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
     * Trvalé odstranění je vyhrazené správci **tohohle prostoru**.
     *
     * Dřív o tom rozhodoval sloupec `users.role`, tedy role účtu, ne role
     * v galerii: kdo si účet založil sám, byl všude „owner" a mohl vysypat
     * cizí koš i s originály na disku — a pozvaný partner (`partner`) naopak
     * nesměl vysypat ten svůj, i když ho z administrace vysypat mohl.
     *
     * Odmítnutí se **říká**: tlačítko, které mlčí, vypadá jako rozbité, a
     * u mazání je to ta horší varianta z obou.
     */
    private function smiMazat(Request $request, GallerySpace $prostor): ?JsonResponse
    {
        $clovek = $request->user();

        if ($clovek === null) {
            return response()->json(['ok' => false, 'zprava' => 'Trvale odstranit smí jen správce prostoru.'], 403);
        }

        if ((int) $prostor->owner_id === (int) $clovek->id) {
            return null;
        }

        // Role v **tomhle** prostoru. `editor` je běžný člen dvojice — ten maže
        // do koše, ne z něj; nevratný krok zůstává vlastníkovi a správci.
        $role = (string) ($prostor->members()->where('users.id', $clovek->id)->first()?->pivot->role ?? '');

        if (in_array($role, ['owner', 'admin'], true)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'zprava' => 'Trvale odstranit smí jen správce prostoru. Do koše to zatím zůstane.',
        ], 403);
    }

    /**
     * Co koš ukazuje, s tím se smí pracovat — nic víc.
     *
     * Fotku z trezoru seznam koše se zamčeným trezorem neukazuje (`System::kos`),
     * ale „Vyprázdnit koš" ji dřív smazal nadobro taky: potvrdilo se číslo, které
     * na obrazovce stálo, a zmizelo i to, co tam nestálo. Podmínka je tatáž
     * jako u seznamu, sezení s odemčeným trezorem.
     *
     * @return Builder<MediaItem>
     */
    private function vKosi(GallerySpace $prostor)
    {
        $trezor = request()->hasSession()
            && (int) request()->session()->get('vault_unlocked_until', 0) > now()->timestamp;

        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('trashed_at')
            ->when(! $trezor, fn ($q) => $q->where('is_hidden', false));
    }
}
