<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Services\Provoz\AdminVeStavu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StateController extends Controller
{
    use UrcujePar;

    public function __construct(private readonly AdminVeStavu $sprava) {}

    public function show(Request $request): JsonResponse
    {
        $state = CoupleState::forCouple($this->parId($request));

        return response()->json([
            'data' => $state->toClientObject(),
            'updated_at' => $state->updated_at?->toIso8601String(),
            'rev' => $state->rev,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['required', 'array'],
            'rev' => ['nullable', 'integer', 'min:0'],
        ]);

        $coupleId = $this->parId($request);
        $uzivatel = $request->user();

        return DB::transaction(function () use ($validated, $coupleId, $uzivatel) {
            $state = CoupleState::where('couple_id', $coupleId)->lockForUpdate()->first()
                ?? CoupleState::forCouple($coupleId);

            // Konflikt: klient staví na starší verzi. Vrátíme aktuální stav,
            // klient ho přijme a překreslí — patch se neaplikuje.
            $clientRev = $validated['rev'] ?? null;
            if ($clientRev !== null && $clientRev < $state->rev) {
                return response()->json([
                    'data' => $state->toClientObject(),
                    'updated_at' => $state->updated_at?->toIso8601String(),
                    'rev' => $state->rev,
                    'conflict' => true,
                ], 409);
            }

            $patch = $validated['data'];

            /*
             * Administrace přichází touhle cestou, ne přes `/api/admin`.
             *
             * Prototyp ji celou drží v komponentě a tlačítka jen mění stav — ten
             * se pak uloží jako všechno ostatní. Kdyby se uložil tak, jak přišel,
             * obrazovka by od prvního kliknutí ukazovala něco, co v databázi
             * neplatí. Záměr se proto provede a odpověď nese skutečnost.
             */
            if ($this->sprava->tykaSe($patch)) {
                $skutecnost = $this->sprava->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);

                /*
                 * Skutečnost se **uloží**, ne jen vrátí.
                 *
                 * Klient si odpověď na `PATCH` jen odloží a nikomu o ní neřekne —
                 * překreslí se až z toho, co přijde příště z `GET /api/state`.
                 * Kdyby tam skutečnost nebyla, zůstalo by na obrazovce viset to,
                 * co si uživatel přál, i když server jeho zásah odmítl.
                 */
                $patch = array_merge($this->sprava->bezSpravy($patch), $skutecnost);
            }

            $state->applyPatch($patch);

            return response()->json([
                'data' => $state->toClientObject(),
                'updated_at' => $state->updated_at?->toIso8601String(),
                'rev' => $state->rev,
            ]);
        });
    }

    public function destroy(Request $request): JsonResponse
    {
        $state = CoupleState::forCouple($this->parId($request));
        $state->update(['data' => [], 'private' => [], 'rev' => 0]);

        return response()->json(['data' => (object) [], 'rev' => 0]);
    }

}
