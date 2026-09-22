<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\HousePantryItem;
use App\Services\Obsah\Domacnost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Spíž v databázi.
 *
 * Spíž se čte z `house_pantry_items`, ale „−/+", „Doplnit", přidání i odebrání
 * položky měnily jen kopii ve stavu (počítač `pantry`, telefon `mPan`, který
 * se neukládal vůbec). Databáze zůstala, jak byla, a každé zařízení ukazovalo
 * něco jiného.
 */
class SpizController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /**
     * Změny najednou.
     *
     * `polozky`: s `id` se změní množství, bez `id` vznikne nová položka
     * (`nazev`, `kategorie`, `jednotka`); `odebrat`: uuid položek k odebrání.
     */
    public function mnozstvi(Request $request, Domacnost $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'polozky' => ['array', 'max:100'],
            'polozky.*.id' => ['nullable', 'uuid'],
            'polozky.*.mnozstvi' => ['required', 'numeric', 'min:0', 'max:100000'],
            'polozky.*.nazev' => ['required_without:polozky.*.id', 'nullable', 'string', 'max:120'],
            'polozky.*.kategorie' => ['nullable', 'string', 'max:40'],
            'polozky.*.jednotka' => ['nullable', 'string', 'max:20'],
            'odebrat' => ['array', 'max:100'],
            'odebrat.*' => ['uuid'],
        ]);

        abort_if(empty($data['polozky']) && empty($data['odebrat']), 422, 'Není co změnit.');

        foreach ($data['polozky'] ?? [] as $p) {
            if (! empty($p['id'])) {
                HousePantryItem::where('gallery_space_id', $prostor->id)
                    ->where('uuid', $p['id'])
                    ->update(['quantity' => round((float) $p['mnozstvi'], 2), 'updated_at' => now()]);

                continue;
            }

            $nazev = trim((string) $p['nazev']);

            HousePantryItem::create([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'name' => $nazev,
                'category' => trim((string) ($p['kategorie'] ?? '')) ?: 'Špajz',
                'quantity' => round((float) $p['mnozstvi'], 2),
                'unit' => trim((string) ($p['jednotka'] ?? '')) ?: 'ks',
                'keywords' => [Str::lower($nazev)],
            ]);
        }

        if (! empty($data['odebrat'])) {
            HousePantryItem::where('gallery_space_id', $prostor->id)->whereIn('uuid', $data['odebrat'])->delete();
        }

        return response()->json(['ok' => true] + $this->obsahPoAkci($obsah, $prostor));
    }
}
