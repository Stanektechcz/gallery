<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\CoupleState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StateController extends Controller
{
    use UrcujePar;

    public function show(Request $request): JsonResponse
    {
        $state = CoupleState::forCouple($this->parId($request));

        return response()->json([
            'data' => $state->toClientArray(),
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

        return DB::transaction(function () use ($validated, $coupleId) {
            $state = CoupleState::where('couple_id', $coupleId)->lockForUpdate()->first()
                ?? CoupleState::forCouple($coupleId);

            // Konflikt: klient staví na starší verzi. Vrátíme aktuální stav,
            // klient ho přijme a překreslí — patch se neaplikuje.
            $clientRev = $validated['rev'] ?? null;
            if ($clientRev !== null && $clientRev < $state->rev) {
                return response()->json([
                    'data' => $state->toClientArray(),
                    'updated_at' => $state->updated_at?->toIso8601String(),
                    'rev' => $state->rev,
                    'conflict' => true,
                ], 409);
            }

            $state->applyPatch($validated['data']);

            return response()->json([
                'data' => $state->toClientArray(),
                'updated_at' => $state->updated_at?->toIso8601String(),
                'rev' => $state->rev,
            ]);
        });
    }

    public function destroy(Request $request): JsonResponse
    {
        $state = CoupleState::forCouple($this->parId($request));
        $state->update(['data' => [], 'private' => [], 'rev' => 0]);

        return response()->json(['data' => [], 'rev' => 0]);
    }

}
