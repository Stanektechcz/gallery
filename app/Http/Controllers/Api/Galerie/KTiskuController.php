<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Services\Media\ArchivMedii;
use App\Services\Obsah\Knihovna;
use App\Services\Tisk\KTisku;
use App\Support\SpaceContext;
use App\Support\Trezor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fotky „k tisku" — rychlá ikona na dlaždici, stažení všech a historie sad.
 *
 * Dvojice si fotku označí k tisku jedním ťuknutím a pak všechny označené
 * stáhne najednou do telefonu. Co se jednou stáhlo, zůstane jako sada, kterou
 * jde stáhnout znovu. Hosté sem nesmí (`dvojice`), trezor a koš se nikdy
 * neoznačí ani nestáhnou. Logika je v `KTisku`.
 */
class KTiskuController extends Controller
{
    use UrcujePar;

    public function __construct(private readonly KTisku $tisk) {}

    /** Seznam „k tisku" a historie stažených sad. */
    public function index(Request $request, Knihovna $knihovna): JsonResponse
    {
        $prostorId = $this->parId($request);
        $oznacene = $this->tisk->oznacene($prostorId);

        return response()->json([
            // Tvar dlaždice knihovny — obrazovka je kreslí týmž kódem jako mřížku.
            'oznacene' => $knihovna->dlazdice($oznacene),
            'pocet' => $this->tisk->pocet($prostorId),
            'strop' => KTisku::STROP,
            'sady' => $this->tisk->sady($prostorId),
        ]);
    }

    /** Označit fotku k tisku. */
    public function oznac(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:64']]);
        $prostorId = $this->parId($request);
        $media = $this->fotka($request, $prostorId, $data['id']);

        return response()->json([
            'ok' => true,
            'oznaceno' => true,
            'pocet' => $this->tisk->oznac($prostorId, $media, $request->user()?->id),
        ]);
    }

    /** Zrušit označení — smí kdokoli z dvojice. */
    public function zrus(Request $request, string $uuid): JsonResponse
    {
        $prostorId = $this->parId($request);
        $media = $this->najdi($prostorId, $uuid);

        return response()->json([
            'ok' => true,
            'oznaceno' => false,
            'pocet' => $this->tisk->zrus($prostorId, $media),
        ]);
    }

    /**
     * „Stáhnout vše k tisku" — sada ze všech označených.
     *
     * Označení se do sady přesunou hned, ne až po stažení: telefon soubory
     * ukládá sám (sdílení nebo archiv) a server se o konci nedozví. Sada tak
     * zůstane v historii i tehdy, když se stažení přerušilo, a jde znovu.
     */
    public function zaloz(Request $request): JsonResponse
    {
        $prostorId = $this->parId($request);
        $sada = $this->tisk->zalozSadu($prostorId, $request->user()?->id);

        abort_if($sada === null, 422, 'K tisku zatím není označená žádná fotka.');
        abort_if($sada === false, 409, 'Fotky k tisku právě stahuje druhý z vás — sada je za chvíli v „Dříve staženo“.');

        return response()->json([
            'ok' => true,
            'zprava' => 'Sada „'.$sada->name.'“ je připravená',
            'sada' => $this->tisk->naKlienta($sada, null, $request->user()?->name),
            'soubory' => $this->tisk->soubory($this->tisk->mediaSady($sada)),
            'zbyva' => $this->tisk->pocet($prostorId),
        ], 201);
    }

    /** Soubory dříve stažené sady — pro „Stáhnout znovu". */
    public function show(Request $request, string $sada): JsonResponse
    {
        $radek = $this->tisk->sada($this->parId($request), $sada);
        $media = $this->tisk->mediaSady($radek);

        return response()->json([
            'sada' => $this->tisk->naKlienta($radek, $media->count()),
            'soubory' => $this->tisk->soubory($media),
        ]);
    }

    /** Sada jako jeden ZIP — složka pojmenovaná po sadě, stažení se zapíše. */
    public function archiv(Request $request, string $sada, ArchivMedii $archiv): BinaryFileResponse
    {
        $radek = $this->tisk->sada($this->parId($request), $sada);

        $odpoved = $archiv->stahnout($this->tisk->mediaSady($radek), $radek->name, $radek->name, $radek->file_stem);

        abort_if($odpoved === null, 404, 'Ze sady aplikace u sebe nemá ani jeden originál.');

        $this->tisk->zapisStazeni($radek);

        return $odpoved;
    }

    /** Telefon uložil sadu přes sdílení — zapíše se stažení. */
    public function stazeno(Request $request, string $sada): JsonResponse
    {
        $radek = $this->tisk->zapisStazeni($this->tisk->sada($this->parId($request), $sada));

        return response()->json(['ok' => true, 'sada' => $this->tisk->naKlienta($radek)]);
    }

    /**
     * Fotka, kterou jde označit.
     *
     * Trezor se zamčeným trezorem se tváří jako neexistující (jako `raw`),
     * aby nešlo zkoušet, které uuid v něm leží. S odemčeným se řekne proč.
     */
    private function fotka(Request $request, int $prostorId, string $uuid): MediaItem
    {
        $media = $this->najdi($prostorId, $uuid);

        abort_if($media->trashed_at !== null, 404, 'Taková fotka tu není.');

        if ($media->is_hidden) {
            abort_unless(Trezor::odemcen($request), 404, 'Taková fotka tu není.');
            abort(422, 'Fotky z trezoru se k tisku neoznačují — nejdřív ji z trezoru vyjměte.');
        }

        abort_if($media->media_type !== 'photo', 422, 'K tisku jdou jen fotky — video se netiskne.');

        return $media;
    }

    private function najdi(int $prostorId, string $uuid): MediaItem
    {
        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostorId)
            ->where('uuid', $uuid)
            ->first();

        abort_if($media === null, 404, 'Taková fotka tu není.');

        return $media;
    }
}
