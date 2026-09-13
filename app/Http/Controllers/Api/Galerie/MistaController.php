<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\Place;
use App\Services\Obsah\Cesty;
use App\Services\Obsah\Tyden;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Místa z galerie: nový cíl na seznam přání, „byli jsme" a společná poznámka.
 *
 * „Nový cíl" i „Poznámka" u místa hlásily „zatím neumíme", zaškrtnutí cíle
 * se drželo jen ve stavu obrazovky. Místa přitom v aplikaci jsou (`places`,
 * `place_notes`) a čte je mapa, cesty i Světový itinerář. Prototyp místa zná
 * jménem, takže se hledají jménem — a jen ve vlastním prostoru.
 */
class MistaController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function store(Request $request, Tyden $tyden): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'mesto' => ['nullable', 'string', 'max:120'],
            'zeme' => ['nullable', 'string', 'max:120'],
            'poznamka' => ['nullable', 'string', 'max:2000'],
        ]);

        $nazev = trim($data['nazev']);

        if ($this->najdi($prostor, $nazev)) {
            return response()->json(['ok' => false, 'zprava' => 'Místo „'.$nazev.'“ už na seznamu je.'], 422);
        }

        DB::transaction(function () use ($prostor, $data, $nazev, $request) {
            $misto = Place::create([
                'gallery_space_id' => $prostor->id,
                'name' => $nazev,
                'city' => $data['mesto'] ?? null,
                'country' => $data['zeme'] ?? null,
                'lifecycle_status' => 'idea',
                'source' => 'galerie',
                'created_by' => $request->user()->id,
            ]);

            if (! empty($data['poznamka'])) {
                $this->ulozPoznamku($prostor, $misto->id, $data['poznamka'], $request->user()->id);
            }
        });

        return response()->json(['ok' => true, 'zprava' => '„'.$nazev.'“ přidáno mezi místa, kam chcete'] + $this->obsahPoAkci($tyden, $prostor), 201);
    }

    /** Byli jsme / zpátky mezi přání. */
    public function stav(Request $request, Tyden $tyden): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'navstiveno' => ['required', 'boolean'],
        ]);

        $misto = $this->najdi($prostor, $data['nazev']);

        if ($misto === null) {
            return response()->json(['ok' => false, 'zprava' => 'Tohle není místo — naplánovanou cestu uzavřete v Cestách.'], 422);
        }

        $misto->update(['lifecycle_status' => $data['navstiveno'] ? 'visited' : 'idea']);

        return response()->json([
            'ok' => true,
            'zprava' => $data['navstiveno'] ? '„'.$misto->name.'“ — byli jsme' : '„'.$misto->name.'“ zpátky mezi přáními',
        ] + $this->obsahPoAkci($tyden, $prostor));
    }

    /** Společná poznámka k místu — jedna na místo, přepíše se. */
    public function poznamka(Request $request, Cesty $cesty): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:5000'],
        ]);

        $misto = $this->najdi($prostor, $data['nazev']);
        abort_if($misto === null, 404, 'Takové místo tu není.');

        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            DB::table('place_notes')->where('place_id', $misto->id)->where('scope_key', 'shared')->delete();
        } else {
            $this->ulozPoznamku($prostor, $misto->id, $text, $request->user()->id);
        }

        return response()->json(['ok' => true, 'zprava' => $text === '' ? 'Poznámka smazána' : 'Poznámka uložena pro oba'] + $this->obsahPoAkci($cesty, $prostor));
    }

    private function najdi(GallerySpace $prostor, string $nazev): ?Place
    {
        return Place::where('gallery_space_id', $prostor->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($nazev))])
            ->first();
    }

    private function ulozPoznamku(GallerySpace $prostor, int $misto, string $text, int $kdo): void
    {
        if (! Schema::hasTable('place_notes')) {
            return;
        }

        $existuje = DB::table('place_notes')->where('place_id', $misto)->where('scope_key', 'shared')->exists();

        if ($existuje) {
            DB::table('place_notes')->where('place_id', $misto)->where('scope_key', 'shared')
                ->update(['content' => trim($text), 'updated_by' => $kdo, 'updated_at' => now()]);

            return;
        }

        DB::table('place_notes')->insert([
            'place_id' => $misto,
            'gallery_space_id' => $prostor->id,
            'user_id' => null,
            'visibility' => 'shared',
            'scope_key' => 'shared',
            'content' => trim($text),
            'created_by' => $kdo,
            'updated_by' => $kdo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
