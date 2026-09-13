<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Services\Obsah\Planovani;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kategorie úkolů — vlastní nástěnky vedle hlavní a domácnosti.
 *
 * Kategorie žila jen ve stavu prohlížeče: úkoly v ní se nikam nezapsaly
 * (nástěnka bez úkolu z databáze se brala jako ukázková) a po obnovení
 * stránky kategorie ukazovala všechny úkoly dvojice. Teď je to seznam
 * úkolů (`shared_todo_lists`) a nástěnka kategorie jsou úkoly v něm.
 */
class KategorieUkoluController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function store(Request $request, Planovani $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $nazev = $this->nazev($request);

        $uuid = (string) Str::uuid();

        DB::table('shared_todo_lists')->insert([
            'uuid' => $uuid,
            'gallery_space_id' => $prostor->id,
            'created_by' => $request->user()->id,
            'title' => $nazev,
            'kind' => Planovani::DRUH_KATEGORIE,
            'sort_order' => (int) DB::table('shared_todo_lists')->where('gallery_space_id', $prostor->id)->max('sort_order') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'zprava' => 'Kategorie „'.$nazev.'“ založena',
            'klic' => Planovani::klicKategorie($uuid),
        ] + $this->obsahPoAkci($obsah, $prostor), 201);
    }

    public function update(Request $request, string $uuid, Planovani $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $nazev = $this->nazev($request);

        $this->kategorie($prostor, $uuid)->update(['title' => $nazev, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'zprava' => 'Kategorie přejmenována na „'.$nazev.'“'] + $this->obsahPoAkci($obsah, $prostor));
    }

    /**
     * Smazání kategorie.
     *
     * Seznam se archivuje a otevřené úkoly v něm se zruší (zůstanou
     * v historii). Hotové zůstávají hotové — jsou to věci, které se staly.
     */
    public function destroy(Request $request, string $uuid, Planovani $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $seznam = $this->kategorie($prostor, $uuid)->first(['id', 'title']);

        $zruseno = DB::transaction(function () use ($prostor, $seznam) {
            $zruseno = SharedTodo::where('gallery_space_id', $prostor->id)
                ->where('list_id', $seznam->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            DB::table('shared_todo_lists')->where('id', $seznam->id)->update(['archived_at' => now(), 'updated_at' => now()]);

            return $zruseno;
        });

        $zprava = 'Kategorie „'.$seznam->title.'“ smazána'
            .($zruseno ? ' · '.$zruseno.' '.($zruseno === 1 ? 'otevřený úkol zrušen' : ($zruseno < 5 ? 'otevřené úkoly zrušeny' : 'otevřených úkolů zrušeno')) : '');

        return response()->json(['ok' => true, 'zprava' => $zprava] + $this->obsahPoAkci($obsah, $prostor));
    }

    private function nazev(Request $request): string
    {
        $data = $request->validate(['nazev' => ['required', 'string', 'max:120']]);
        $nazev = trim($data['nazev']);

        abort_if($nazev === '', 422, 'Kategorie potřebuje název');

        return $nazev;
    }

    /**
     * Vlastní kategorie dvojice.
     *
     * Jen seznamy založené jako kategorie — domácnost ani výchozí „Společné
     * úkoly" (do nich píše zbytek aplikace) se odsud přejmenovat ani smazat
     * nedají.
     */
    private function kategorie(GallerySpace $prostor, string $uuid): Builder
    {
        $dotaz = fn () => DB::table('shared_todo_lists')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $uuid)
            ->whereNull('archived_at')
            ->where('kind', Planovani::DRUH_KATEGORIE);

        abort_unless($dotaz()->exists(), 404, 'Kategorie nenalezena');

        return $dotaz();
    }
}
