<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\System;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vyřešení rozporu mezi aplikací a Diskem.
 *
 * „Vyřešeno" hlásilo tlačítko a přepsalo jediné pole ve stavu prohlížeče.
 * Rozpor v `drive_conflicts` zůstal otevřený, takže se při dalším načtení
 * vrátil — a druhý z dvojice ho viděl celou dobu.
 *
 * Zapisuje se **rozhodnutí, ne provedení**: která strana platí, kdo to řekl
 * a kdy. Samotné srovnání souboru na Disku dělá synchronizace, která si
 * vyřešený rozpor přečte.
 */
class RozporController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Co si dvojice může vybrat. Cizí slovo je 422, ne tiché uložení. */
    public const VOLBY = ['mine', 'theirs', 'merged'];

    public function __construct(private readonly System $obsah) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:40'],
            'volba' => ['required', 'string', 'in:'.implode(',', self::VOLBY)],
        ]);

        $prostor = GallerySpace::findOrFail($this->parId($request));

        abort_unless(Schema::hasTable('drive_conflicts'), 503, 'Rozpory budou dostupné po dokončení aktualizace databáze.');

        // Obrazovka nese `r12`, tabulka číslo. Prefix odlišuje skutečný rozpor
        // od ukázkového `c1` z `galerie-data.js`, který v tabulce není.
        abort_unless(preg_match('/^r(\d+)$/', $data['id'], $c) === 1, 404, 'Takový otevřený rozpor tu není.');

        $cislo = (int) $c[1];

        $rozpor = DB::table('drive_conflicts as r')
            ->join('storage_connections as s', 's.id', '=', 'r.storage_connection_id')
            ->where('s.gallery_space_id', $prostor->id)
            ->where('r.id', $cislo)
            ->whereNull('r.resolved_at')
            ->first(['r.id']);

        abort_if($rozpor === null, 404, 'Takový otevřený rozpor tu není.');

        DB::table('drive_conflicts')->where('id', $rozpor->id)->update([
            'resolution' => $data['volba'],
            'resolved_at' => CarbonImmutable::now(),
            'resolved_by' => $request->user()?->id,
            'updated_at' => now(),
        ]);

        return response()->json([
            'zprava' => 'Rozpor vyřešen.',
            'ok' => true,
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }
}
