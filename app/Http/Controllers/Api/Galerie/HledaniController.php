<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Hledani\HledaniMedii;
use App\Services\Obsah\Knihovna;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hledání fotek pro prototyp — `GET /api/hledat?q=…`.
 *
 * Prototyp filtroval jen to, co měl v prohlížeči: 240 nejnovějších fotek
 * z `PHOTOS`. Fotka z loňského léta se tak nenašla nikdy, ať se hledalo
 * čímkoli. Tady se hledá v celé knihovně (`HledaniMedii`) a odpověď nese
 * dlaždice v tvaru `PHOTOS` (`Knihovna::dlazdice()`), aby je obrazovka
 * nakreslila beze změny.
 *
 * Rozsah jako mřížka: jeden prostor, bez koše, bez trezoru. Host sem nesmí
 * (`dvojice` na celé skupině), stejně jako k `/api/data/knihovna`.
 */
class HledaniController extends Controller
{
    use UrcujePar;

    /** Kolik dlaždic přijde bez `limit`. */
    public const NA_STRANU = 60;

    public function __invoke(Request $request, HledaniMedii $hledani, Knihovna $knihovna): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'strana' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.HledaniMedii::LIMIT_MAX],
            'druh' => ['nullable', 'in:photo,video'],
        ]);

        $prostor = GallerySpace::findOrFail($this->parId($request));
        $strana = (int) ($data['strana'] ?? 1);
        $limit = (int) ($data['limit'] ?? self::NA_STRANU);
        $vysledek = $hledani->hledej($prostor, $data['q'], ['media_type' => $data['druh'] ?? null], $limit, $strana);

        return response()->json([
            'q' => trim($data['q']),
            'polozky' => $knihovna->dlazdice($vysledek['polozky'], ($strana - 1) * $limit),
            'celkem' => $vysledek['celkem'],
            'uroven' => $vysledek['uroven'],
            'strana' => $strana,
            'limit' => $limit,
            'dalsi' => $strana * $limit < $vysledek['celkem'],
        ])
            // Jako skupina `knihovna`: po přepnutí účtu na témže zařízení nesmí
            // mezipaměť prohlížeče vydat výsledky toho druhého.
            ->header('Cache-Control', 'private, no-store');
    }
}
