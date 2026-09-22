<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\HouseChore;
use App\Services\Obsah\Domacnost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Domácí práce: založit a odebrat.
 *
 * Dělba šla jen prohlížet a přehazovat. Práce vznikaly „prvním dotekem"
 * ukázky (DomacnostVeStavu::prvniPrace), jenže dvojice s prázdnou domácností
 * ukázku nevidí — a neměla tak jak dělbu vůbec začít. Nová práce z počítače
 * nebo z telefonu se zapíše rovnou sem; „Mám hotovo", přehození a rotace
 * dál jdou přes stav.
 */
class DomaciPraceController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Jak často — slova, podle kterých HouseChore::dniOpakovani pozná zpoždění. */
    public const JAK_CASTO = ['denně', '2× týdně', 'týdně', 'měsíčně', 'podle potřeby'];

    public function __construct(private readonly Domacnost $obsah) {}

    public function store(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);

        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'jak_casto' => ['nullable', 'string', 'in:'.implode(',', self::JAK_CASTO)],
            'minuty' => ['nullable', 'integer', 'between:5,600'],
            // Kdo ji má teď: já, ten druhý, nebo nikdo konkrétní (spolu).
            'kdo' => ['nullable', 'in:ja,druhy,spolu'],
            'rotace' => ['sometimes', 'boolean'],
        ], [
            'jak_casto.in' => 'Jak často: denně, 2× týdně, týdně, měsíčně nebo podle potřeby.',
            'minuty.between' => 'Čas napište v minutách, od 5 do 600.',
        ]);

        $nazev = trim($data['nazev']);

        $uz = HouseChore::where('gallery_space_id', $prostor->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nazev)])
            ->exists();

        if ($uz) {
            return response()->json(['ok' => false, 'zprava' => 'Práci „'.$nazev.'“ už v dělbě máte.'], 422);
        }

        $kdo = $data['kdo'] ?? 'ja';

        HouseChore::create([
            'gallery_space_id' => $prostor->id,
            'name' => $nazev,
            'every' => $data['jak_casto'] ?? 'týdně',
            'minutes' => (int) ($data['minuty'] ?? 30),
            'assigned_to' => match ($kdo) {
                'ja' => $request->user()->id,
                'druhy' => $this->druhy($prostor, (int) $request->user()->id),
                default => null,
            },
            // Práce „spolu" nemá komu se střídat.
            'rotate' => $kdo === 'spolu' ? false : (bool) ($data['rotace'] ?? true),
            'icon' => $this->ikona($nazev),
            'sort_order' => (int) HouseChore::where('gallery_space_id', $prostor->id)->max('sort_order') + 1,
        ]);

        return $this->hotovo($prostor, 'Práce „'.$nazev.'“ přidána do dělby', 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $prostor = $this->prostor($request);

        $prace = HouseChore::where('gallery_space_id', $prostor->id)->where('uuid', $uuid)->firstOrFail();
        $nazev = $prace->name;

        // Záznamy „kdo co udělal" zůstávají — patří k férovosti posledních 30 dní.
        $prace->delete();

        return $this->hotovo($prostor, 'Práce „'.$nazev.'“ odebrána z dělby — co už kdo udělal, v historii zůstává');
    }

    private function prostor(Request $request): GallerySpace
    {
        abort_if((bool) $request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze měnit dělbu.');

        return GallerySpace::findOrFail($this->parId($request));
    }

    private function druhy(GallerySpace $prostor, int $ja): ?int
    {
        $id = $prostor->members()
            ->where('users.id', '!=', $ja)
            ->orderBy('users.id')
            ->value('users.id');

        return $id === null ? null : (int) $id;
    }

    /** Ikona podle slova v názvu; bez shody koště. */
    private function ikona(string $nazev): string
    {
        $t = Str::lower(Str::ascii($nazev));

        foreach ([
            'ph-cooking-pot' => ['var', 'kuch', 'obed', 'vecer'],
            'ph-shopping-cart' => ['nakup', 'obchod'],
            'ph-t-shirt' => ['pradl', 'zehl'],
            'ph-fork-knife' => ['nadob', 'mycka'],
            'ph-bathtub' => ['koupeln', 'wc', 'zachod'],
            'ph-trash' => ['odpad', 'kos', 'smeti', 'trid'],
            'ph-plant' => ['zal', 'kytk', 'zahrad', 'trav', 'sek'],
            'ph-paw-print' => ['pes', 'kock', 'venc', 'zvir'],
            'ph-car' => ['aut', 'tank'],
        ] as $ikona => $slova) {
            foreach ($slova as $slovo) {
                if (str_contains($t, $slovo)) {
                    return $ikona;
                }
            }
        }

        return 'ph-broom';
    }

    private function hotovo(GallerySpace $prostor, string $zprava, int $kod = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'zprava' => $zprava] + $this->obsahPoAkci($this->obsah, $prostor), $kod);
    }
}
