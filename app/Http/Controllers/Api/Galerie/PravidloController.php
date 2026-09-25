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
use Illuminate\Support\Facades\Log;

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
            /*
             * Motor svůj vlastní, česky napsaný důvod hlásí přesně jako
             * `\RuntimeException` (viz `AutomationEngine::spustRucne`/`perform`) —
             * ten se pošle beze změny. `QueryException` je ale taky potomek
             * `\RuntimeException` (přes PHP vestavěnou `\PDOException`), a ten
             * nese SQLSTATE i hodnoty z dotazu — proto se pozná přesnou třídou,
             * ne `instanceof`, a do odpovědi nejde nikdy.
             */
            $vlastniDuvod = $e::class === \RuntimeException::class;

            $zprava = $vlastniDuvod
                ? $e->getMessage()
                : 'Pravidlo se nepovedlo spustit — podrobnosti jsou v logu serveru.';

            if (! $vlastniDuvod) {
                Log::error('Ruční spuštění pravidla selhalo', [
                    'pravidlo' => $radek->uuid,
                    'vyjimka' => $e::class,
                    'zprava' => $e->getMessage(),
                ]);
            }

            // Odpověď nese i historii: neúspěšný běh se zapsal a obrazovka ho
            // má ukázat stejně jako povedený.
            return response()->json([
                'zprava' => $zprava,
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
