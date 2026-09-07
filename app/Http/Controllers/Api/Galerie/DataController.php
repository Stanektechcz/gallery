<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\PoskytovatelObsahu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

            /*
             * Jedna rozbitá kolekce nesmí vzít celou skupinu.
             *
             * `system` staví přes dvacet kolekcí — zámek, úložiště, zdraví
             * dat, trezor, akční inbox. Když jedna z nich hodí výjimku,
             * odpovědí je 500 a klient přijde o všechny; obrazovky pak tiše
             * ukazují ukázková data a nikde není vidět, že se něco stalo.
             * Přesně to se dělo na produkci, kde `/api/data/system` vracelo
             * 500 a lokálně týž kód procházel.
             *
             * Zapíše se to do logu se jménem skupiny, řekne se to i klientovi
             * a skupina se pošle prázdná — což je stav, se kterým prototyp
             * počítá. Ve vývoji se výjimka nechá projít, ať se na ni přijde
             * dřív, než se nasadí.
             */
            try {
                $data = $poskytovatel->kolekce($prostor);
            } catch (\Throwable $e) {
                if (config('app.debug')) {
                    throw $e;
                }

                Log::error("Skupina obsahu „{$skupina}\" se nepodařila sestavit", [
                    'vyjimka' => $e::class,
                    'zprava' => $e->getMessage(),
                    'kde' => $e->getFile().':'.$e->getLine(),
                    'prostor' => $prostor->id,
                ]);

                return response()->json([
                    'data' => (object) [],
                    'uplne' => [],
                    'chyba' => 'Skupinu se nepodařilo sestavit — podrobnosti jsou v logu serveru.',
                ]);
            }

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
