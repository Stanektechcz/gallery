<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Services\Obsah\Tyden;
use App\Support\Cas;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Úkol po termínu na příští týden.
 *
 * Týdenní přehled měl u nedotažených věcí tlačítko „Na příští týden", které
 * u dvojice jen otevřelo plán — termín zůstal v minulosti a úkol visel
 * v „nedotaženém" dál.
 */
class UkolyController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /**
     * Termín se posune na pondělí příštího týdne, hodina zůstane.
     *
     * Pondělí se počítá v pásmu dvojice: v neděli ve 23:30 je „příští týden"
     * zítřek, ne pondělí za osm dní podle hodin serveru.
     */
    public function naPristiTyden(Request $request, string $uuid, Tyden $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $ukol = SharedTodo::query()
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_if(in_array($ukol->status, ['completed', 'cancelled'], true), 422, 'Úkol už je uzavřený — posouvat ho není kam.');

        $pondeli = Cas::dnes()->next(CarbonImmutable::MONDAY);
        $puvodni = $ukol->due_at ? CarbonImmutable::parse($ukol->due_at) : null;
        $novy = $puvodni ? $pondeli->setTime($puvodni->hour, $puvodni->minute) : $pondeli->setTime(9, 0);

        $ukol->update(['due_at' => $novy]);

        return response()->json([
            'ok' => true,
            'zprava' => '„'.$ukol->title.'“ přesunuto na pondělí '.$novy->format('j. n.'),
        ] + $this->obsahPoAkci($obsah, $prostor));
    }
}
