<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Services\Obsah\Planovani;
use App\Services\Obsah\Tyden;
use App\Services\Planning\CalendarEventCreationService;
use App\Support\Cas;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     * Akce do kalendáře k vybranému dni — pro oba.
     *
     * „Přidat do plánu" v kalendáři telefonu zakládal úkol bez data, takže se
     * ve vybraném dni nikdy neobjevil. Čas je podle hodin (jako u akcí
     * z počítače, PlanovaniVeStavu); bez času je akce celodenní.
     */
    public function udalost(Request $request, CalendarEventCreationService $udalosti, Planovani $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'datum' => ['required', 'date_format:Y-m-d'],
            'cas' => ['nullable', 'date_format:G:i'],
            'poznamka' => ['nullable', 'string', 'max:5000'],
        ], [
            'cas.date_format' => 'Čas napište jako 18:30.',
        ]);

        $cas = $data['cas'] ?? null;
        $zacatek = CarbonImmutable::createFromFormat('Y-m-d G:i', $data['datum'].' '.($cas ?: '0:00'));

        $udalosti->create($prostor, $request->user(), [
            'uuid' => (string) Str::uuid(),
            'title' => trim($data['nazev']),
            'description' => $data['poznamka'] ?? null,
            'type' => 'event',
            'status' => 'planned',
            'starts_at' => $zacatek->format('Y-m-d H:i:s'),
            'all_day' => $cas === null,
            'timezone' => 'Europe/Prague',
            'is_private' => false,
            'metadata' => ['source' => 'galerie-telefon'],
        ]);

        return response()->json([
            'ok' => true,
            'zprava' => '„'.trim($data['nazev']).'“ je v kalendáři · '.$zacatek->format('j. n.').($cas ? ' v '.$cas : ''),
        ] + $this->obsahPoAkci($obsah, $prostor), 201);
    }

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
