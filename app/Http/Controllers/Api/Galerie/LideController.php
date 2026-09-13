<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\Person;
use App\Services\Obsah\Knihovna;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lidé v galerii — přejmenovat, skrýt z hledání, sloučit.
 *
 * Obrazovka osoby to všechno uměla jen ve stavu prohlížeče (`pName`,
 * `pHidden`, `pMerged`). Dialog přitom sliboval, že se jméno „propíše do
 * hledání, do detailu fotky i do popisků albumů" — hledání, detail fotky
 * i druhé rozhraní ale čtou tabulku `people`, takže po načtení se vrátilo
 * staré jméno a sloučená osoba byla zase dvakrát.
 */
class LideController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(private readonly Knihovna $obsah) {}

    public function update(Request $request, int $osoba): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->najdi($prostor, $osoba);

        $data = $request->validate([
            'jmeno' => ['sometimes', 'required', 'string', 'max:120'],
            'skryta' => ['sometimes', 'boolean'],
        ]);

        $zmeny = [];

        if (array_key_exists('jmeno', $data)) {
            $zmeny['name'] = trim($data['jmeno']);
        }

        if (array_key_exists('skryta', $data)) {
            $zmeny['is_hidden'] = (bool) $data['skryta'];
        }

        if ($zmeny === [] || ($zmeny['name'] ?? null) === '') {
            return response()->json(['ok' => false, 'zprava' => 'Není co uložit.'], 422);
        }

        $radek->update($zmeny);

        AuditLog::record('person.update', $radek, array_keys($zmeny));

        $zprava = match (true) {
            isset($zmeny['name']) => 'Osoba přejmenována na „'.$radek->name.'“',
            $radek->is_hidden => $radek->name.' skryto z hledání',
            default => $radek->name.' je zpět v hledání',
        };

        return $this->odpoved($prostor, $zprava, $radek);
    }

    /**
     * Sloučit do jiné osoby: fotky a alba přejdou, zdrojová osoba zanikne.
     *
     * Vrátit to nejde — po sloučení už nikdo neví, která fotka patřila komu.
     * Obrazovka se proto ptá předem.
     */
    public function sluc(Request $request, int $osoba): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $zdroj = $this->najdi($prostor, $osoba);
        $data = $request->validate(['do' => ['required', 'integer']]);

        if ((int) $data['do'] === $zdroj->id) {
            return response()->json(['ok' => false, 'zprava' => 'Osobu nejde sloučit samu se sebou.'], 422);
        }

        $cil = $this->najdi($prostor, (int) $data['do']);

        $pocet = DB::transaction(function () use ($zdroj, $cil) {
            $fotky = DB::table('media_person')->where('person_id', $zdroj->id)->get(['media_item_id', 'tagged_by', 'created_at']);

            DB::table('media_person')->insertOrIgnore($fotky->map(fn (object $r) => [
                'media_item_id' => $r->media_item_id,
                'person_id' => $cil->id,
                'tagged_by' => $r->tagged_by,
                'created_at' => $r->created_at ?? now(),
            ])->all());

            $alba = DB::table('album_person')->where('person_id', $zdroj->id)->get(['album_id', 'created_at']);

            DB::table('album_person')->insertOrIgnore($alba->map(fn (object $r) => [
                'album_id' => $r->album_id,
                'person_id' => $cil->id,
                'created_at' => $r->created_at ?? now(),
            ])->all());

            if ($cil->cover_media_id === null && $zdroj->cover_media_id !== null) {
                $cil->update(['cover_media_id' => $zdroj->cover_media_id]);
            }

            // Poznámky k osobě patří k člověku, ne k záznamu — přejdou taky,
            // pokud cílová osoba poznámku téhož druhu (`scope_key`) ještě nemá.
            if (DB::getSchemaBuilder()->hasTable('person_notes')) {
                $ma = DB::table('person_notes')->where('person_id', $cil->id)->pluck('scope_key')->all();

                DB::table('person_notes')
                    ->where('person_id', $zdroj->id)
                    ->whereNotIn('scope_key', $ma)
                    ->update(['person_id' => $cil->id]);
            }

            $zdroj->delete();

            return $fotky->count();
        });

        AuditLog::record('person.merge', $cil, ['z' => $zdroj->name, 'fotek' => $pocet]);

        return $this->odpoved($prostor, 'Sloučeno do „'.$cil->name.'“ · fotek: '.$pocet, $cil);
    }

    private function najdi(GallerySpace $prostor, int $id): Person
    {
        return Person::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->findOrFail($id);
    }

    private function odpoved(GallerySpace $prostor, string $zprava, Person $osoba): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'zprava' => $zprava,
            'osoba' => $osoba->id,
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }
}
