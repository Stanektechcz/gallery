<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\PoskytovatelObsahu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Obsah, který prototyp kreslí — ze skutečné databáze.
 *
 * Prototyp čte `window.GalerieData` synchronně při vykreslení, takže na server
 * čekat neumí. Data se proto stahují po skupinách a **přimíchávají** do už
 * existujícího objektu; do té doby (a když skupina nedorazí) zůstávají ukázková.
 * Prázdná obrazovka a rozbitá aplikace vypadají z pohledu člověka stejně.
 *
 * Po skupinách, ne jednou velkou odpovědí: obrazovka financí nemá čekat na to,
 * až se spočítá kuchařka.
 */
class DataController extends Controller
{
    use UrcujePar;

    /** @param  iterable<PoskytovatelObsahu>  $poskytovatele */
    public function __construct(private readonly iterable $poskytovatele) {}

    public function __invoke(Request $request, string $skupina): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        foreach ($this->poskytovatele as $poskytovatel) {
            if ($poskytovatel->skupina() !== $skupina) {
                continue;
            }

            $data = $poskytovatel->kolekce($prostor);

            // Klient přepisuje klíče a nemaže je; u kolekcí, které server dodává
            // celé, by mu tak vedle skutečných dat zůstala ukázka.
            $uplne = array_values(array_filter(
                $poskytovatel->uplne(),
                fn (string $klic) => array_key_exists($klic, $data),
            ));

            return response()->json(['data' => $data, 'uplne' => $uplne])
                // Krátká paměť: obsah se mění po zápisu, ne po vteřině, a panel
                // i obrazovky se překreslují častěji, než se data mění.
                ->header('Cache-Control', 'private, max-age=30');
        }

        abort(404, 'Takovou skupinu obsahu server nezná.');
    }
}
