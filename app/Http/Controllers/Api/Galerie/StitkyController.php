<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Services\Obsah\Knihovna;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sloučení štítků, které jsou jeden štítek psaný dvakrát.
 *
 * Záložka „Ke sloučení" ukazovala skutečné dvojice z databáze (`#Chorvatsko
 * + #chorvatsko`), ale tlačítko „Sloučit" jen přepnulo štítek řádku na
 * „sloučeno". Fotky zůstaly rozdělené pod dvěma štítky a řádek se po načtení
 * vrátil.
 *
 * Sloučí se jen štítky, které se opravdu liší velikostí písmen, diakritikou
 * nebo mezerou — jiné dvojice obrazovka nenabízí a server je nepřijme.
 * Štítek s podštítky se neslučuje: strom štítků by se musel přestavět a to
 * patří do správy štítků, ne do jednoho klepnutí.
 */
class StitkyController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(private readonly Knihovna $obsah) {}

    public function sluc(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'stitky' => ['required', 'array', 'min:2', 'max:10'],
            'stitky.*' => ['required', 'string', 'max:255'],
        ]);

        $jmena = collect($data['stitky'])
            ->map(fn (string $j) => ltrim(trim($j), '#'))
            ->filter()
            ->unique()
            ->values();

        $stitky = DB::table('tags')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('name', $jmena->all())
            ->get(['id', 'name']);

        if ($stitky->count() < 2) {
            return response()->json(['ok' => false, 'zprava' => 'Tyhle štítky už sloučené jsou — obnovte seznam.'], 422);
        }

        if ($stitky->map(fn (object $t) => $this->klic((string) $t->name))->unique()->count() > 1) {
            return response()->json(['ok' => false, 'zprava' => 'Sloučit jde jen stejný štítek psaný různě.'], 422);
        }

        if (DB::table('tags')->whereIn('parent_id', $stitky->pluck('id'))->exists()) {
            return response()->json(['ok' => false, 'zprava' => 'Jeden ze štítků má podštítky — sloučení udělejte ve správě štítků.'], 422);
        }

        // Zůstává štítek s nejvíc fotkami; při shodě ten starší.
        $pocty = DB::table('media_tag')->whereIn('tag_id', $stitky->pluck('id'))
            ->groupBy('tag_id')->pluck(DB::raw('count(*)'), 'tag_id');
        $cil = $stitky->sortBy([
            fn (object $a, object $b) => ((int) ($pocty[$b->id] ?? 0)) <=> ((int) ($pocty[$a->id] ?? 0)),
            fn (object $a, object $b) => $a->id <=> $b->id,
        ])->first();
        $zdroje = $stitky->where('id', '!=', $cil->id)->pluck('id')->all();

        DB::transaction(function () use ($cil, $zdroje) {
            $this->presun('media_tag', 'media_item_id', $cil->id, $zdroje);
            $this->presun('album_tag', 'album_id', $cil->id, $zdroje);

            if (Schema::hasTable('tag_assignments')) {
                foreach (DB::table('tag_assignments')->whereIn('tag_id', $zdroje)->get() as $p) {
                    $uz = DB::table('tag_assignments')->where('tag_id', $cil->id)
                        ->where('entity_type', $p->entity_type)->where('entity_id', $p->entity_id)->exists();

                    $uz
                        ? DB::table('tag_assignments')->where('id', $p->id)->delete()
                        : DB::table('tag_assignments')->where('id', $p->id)->update(['tag_id' => $cil->id, 'updated_at' => now()]);
                }
            }

            DB::table('tags')->whereIn('id', $zdroje)->delete();
        });

        AuditLog::record('tag.merge', null, ['do' => $cil->id, 'z' => $zdroje]);

        return response()->json([
            'ok' => true,
            'zprava' => 'Sloučeno pod #'.$cil->name.' · '.$this->pocet(count($zdroje), 'štítek', 'štítky', 'štítků').' zrušen'.(count($zdroje) === 1 ? '' : 'o'),
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * Vazby ze zdrojových štítků na cílový, bez dvojic.
     *
     * @param  list<int>  $zdroje
     */
    private function presun(string $tabulka, string $sloupec, int $cil, array $zdroje): void
    {
        if (! Schema::hasTable($tabulka)) {
            return;
        }

        $uz = DB::table($tabulka)->where('tag_id', $cil)->pluck($sloupec)->all();

        DB::table($tabulka)->whereIn('tag_id', $zdroje)->whereIn($sloupec, $uz)->delete();

        // Zbylé vazby mohou mít týž objekt dvakrát (pod dvěma zdroji) — nechá se první.
        $zbyle = DB::table($tabulka)->whereIn('tag_id', $zdroje)->get();
        $videno = [];

        foreach ($zbyle as $v) {
            $id = $v->{$sloupec};

            if (isset($videno[$id])) {
                DB::table($tabulka)->where('tag_id', $v->tag_id)->where($sloupec, $id)->delete();

                continue;
            }

            $videno[$id] = true;
            DB::table($tabulka)->where('tag_id', $v->tag_id)->where($sloupec, $id)->update(['tag_id' => $cil]);
        }
    }

    /** Stejné pravidlo jako u nabídky ke sloučení (Knihovna::klicStitku). */
    private function klic(string $jmeno): string
    {
        $bez = Str::ascii(mb_strtolower(trim($jmeno)));

        return preg_replace('/[^a-z0-9]/', '', $bez) ?? $bez;
    }

    private function pocet(int $n, string $jeden, string $dva, string $pet): string
    {
        return $n.' '.($n === 1 ? $jeden : ($n >= 2 && $n <= 4 ? $dva : $pet));
    }
}
