<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\GallerySpace;
use App\Services\Automation\AutomationEngine;
use App\Services\Obsah\Pravidla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * „Spustit teď" — pravidlo se doopravdy provede.
 *
 * Tlačítko dosud jen napsalo do historie větu o tom, co by se bylo stalo,
 * a nestalo se nic: úkol nevznikl, zápis se nezaložil, historie tvrdila opak.
 */
class PravidloController extends Controller
{
    use UrcujePar;

    public function __construct(
        private readonly AutomationEngine $motor,
        private readonly Pravidla $obsah,
    ) {}

    public function __invoke(Request $request, string $pravidlo): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $radek = AutomationRule::where('gallery_space_id', $prostor->id)
            ->where('uuid', $pravidlo)
            ->firstOrFail();

        try {
            $zprava = $this->motor->spustRucne($radek, $prostor);
        } catch (\Throwable $e) {
            // Odpověď nese i historii: neúspěšný běh se zapsal a obrazovka ho
            // má ukázat stejně jako povedený.
            return response()->json([
                'zprava' => $e->getMessage(),
                'ok' => false,
            ] + $this->obsahPravidel($prostor), 422);
        }

        return response()->json([
            'zprava' => $zprava,
            'ok' => true,
        ] + $this->obsahPravidel($prostor));
    }

    /** @return array<string, mixed> */
    private function obsahPravidel(GallerySpace $prostor): array
    {
        $obsah = $this->obsah->kolekce($prostor);

        return [
            'rules' => $obsah['RULEDEF'] ?? [],
            'ruleLog' => $obsah['RULOG'] ?? [],
        ];
    }
}
