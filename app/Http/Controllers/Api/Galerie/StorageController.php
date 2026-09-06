<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Provoz\UlozisteGalerie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Čísla pro postranní panel.
 *
 * Vlastní adresa, ne součást `/api/admin`: panel vidí na každé obrazovce každý,
 * kdežto do administrace smí jen vlastník a správce a její přehled je mnohem
 * dražší na spočítání.
 */
class StorageController extends Controller
{
    use UrcujePar;

    public function __invoke(Request $request, UlozisteGalerie $uloziste): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        return response()->json(['data' => $uloziste->panel($prostor)])
            // Krátká paměť v prohlížeči: panel je na každé obrazovce, ale čísla
            // se mění po nahrání souboru, ne po vteřině.
            ->header('Cache-Control', 'private, max-age=60');
    }
}
