<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Services\Obsah\Denik;
use App\Services\Obsah\Planovani;
use App\Services\Planning\SharedTodoService;
use App\Support\Cas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Rychlý zápis z telefonu: zápis do deníku a úkol.
 *
 * List „Nový zápis" / „Nový úkol" a rychlý vstup hlásily „Zápis uložen"
 * a „Úkol přidán" — a přidaly řádek jen do paměti telefonu. Deník i nástěnka
 * se ale skládají z databáze, takže po obnovení stránky zápis zmizel a druhý
 * z dvojice ho neviděl nikdy.
 */
class RychlyZapisController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /**
     * Zápis do deníku — soukromý, jako každý nový zápis.
     *
     * Sdílet ho jde až v deníku; rychlý zápis nemá nikoho překvapit tím, že
     * jeho věta z tramvaje je vidět druhému.
     */
    public function denik(Request $request, Denik $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'nadpis' => ['nullable', 'string', 'max:160'],
            'text' => ['required', 'string', 'max:20000'],
        ]);

        JournalEntry::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $request->user()->id,
            'title' => trim((string) ($data['nadpis'] ?? '')) ?: null,
            'body' => trim($data['text']),
            'entry_date' => Cas::dnes()->toDateString(),
            'visibility' => JournalEntry::VISIBILITY_PRIVATE,
        ]);

        return response()->json(['ok' => true, 'zprava' => 'Zápis uložen do deníku · vidíte ho jen vy'] + $this->obsahPoAkci($obsah, $prostor), 201);
    }

    /**
     * Úkol do společného seznamu.
     *
     * `kdo` je volný text z listu („Adrian", „spolu", „zítra"): úkol dostane
     * člověka, jen když text začíná křestním jménem někoho z dvojice.
     */
    public function ukol(Request $request, SharedTodoService $ukoly, Planovani $obsah): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:180'],
            'kdo' => ['nullable', 'string', 'max:120'],
        ]);

        $kdo = mb_strtolower(trim((string) ($data['kdo'] ?? '')));
        $prirazeno = null;

        if ($kdo !== '') {
            foreach ($prostor->members()->get(['users.id', 'users.name']) as $clen) {
                $krestni = mb_strtolower(Str::before(trim((string) $clen->name), ' '));

                if ($krestni !== '' && str_starts_with($kdo, $krestni)) {
                    $prirazeno = (int) $clen->id;
                    break;
                }
            }
        }

        $ukoly->create($prostor, $request->user(), [
            'title' => trim($data['nazev']),
            'assigned_to' => $prirazeno,
        ], null, null, 'rychly-zapis');

        return response()->json(['ok' => true, 'zprava' => 'Úkol přidán do společného seznamu'] + $this->obsahPoAkci($obsah, $prostor), 201);
    }
}
