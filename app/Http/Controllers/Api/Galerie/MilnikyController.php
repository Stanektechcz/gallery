<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\Denik;
use App\Services\Planning\RelationshipMilestoneService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Milník vztahu z galerie.
 *
 * „Přidat milník" v Milnících a výročích zakládalo **nápad na dárek**
 * s názvem „Nový milník" a hláškou „Milník založen v Dárcích a nápadech".
 * Osa přitom čte `relationship_milestones` — milník se na ní nikdy neobjevil.
 */
class MilnikyController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function store(Request $request, RelationshipMilestoneService $milniky, Denik $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'datum' => ['required', 'string', 'max:20'],
            'poznamka' => ['nullable', 'string', 'max:5000'],
            'kazdy_rok' => ['nullable', 'boolean'],
        ]);

        $den = $this->den($data['datum']);

        if ($den === null) {
            return response()->json(['ok' => false, 'zprava' => 'Datum napište jako 14. 2. 2019.'], 422);
        }

        $nazev = trim($data['nazev']);

        $milniky->create($prostor->id, (int) $request->user()->id, [
            'title' => $nazev,
            'description' => $data['poznamka'] ?? null,
            'occurred_on' => $den->toDateString(),
            'remind_annually' => $data['kazdy_rok'] ?? true,
            'visibility' => 'shared',
        ], 'galerie');

        return response()->json([
            'ok' => true,
            'zprava' => 'Milník „'.$nazev.'“ je na ose · '.$den->format('j. n. Y'),
        ] + $this->obsahPoAkci($obsah, $prostor), 201);
    }

    /** „14. 2. 2019" nebo „2019-02-14"; nesmyslné datum (31. 2.) neprojde. */
    private function den(string $text): ?CarbonImmutable
    {
        $text = trim($text);

        if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $text, $m)) {
            [$d, $mes, $r] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
            [$r, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mes, $d, $r) ? CarbonImmutable::create($r, $mes, $d) : null;
    }
}
