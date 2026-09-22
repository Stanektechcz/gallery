<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Services\Obsah\Denik;
use App\Support\Cas;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deník z galerie na počítači: nový zápis, úprava a smazání.
 *
 * Dialog „Nový zápis" hlásil „Zapsáno" a řádek držel jen ve stavu obrazovky.
 * Deník se přitom skládá z `journal_entries` — telefon ho neviděl, druhý
 * z dvojice taky ne, a zápis nešlo najít v hledání.
 *
 * Soukromí platí jako v aplikaci: cizí soukromý zápis nejde upravit, smazat
 * ani najít podle identifikátoru. Upravovat a mazat smí jen autor.
 */
class DenikController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    private const NALADY = ['radost', 'klid', 'láska', 'únava', 'smutek', 'vztek', 'vděk', 'nejistota'];

    public function __construct(private readonly Denik $obsah) {}

    public function store(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $data = $this->over($request);

        JournalEntry::create([
            'gallery_space_id' => $prostor->id,
            'created_by' => $request->user()->id,
            'title' => trim($data['nadpis']),
            'body' => trim($data['text']),
            'mood' => $data['nalada'] ?? null,
            // Dnešek dvojice: zápis po půlnoci patří k novému dni i mezi 0:00 a 2:00.
            'entry_date' => $data['datum'] ?? Cas::dnes()->toDateString(),
            'visibility' => ! empty($data['soukromy']) ? JournalEntry::VISIBILITY_PRIVATE : JournalEntry::VISIBILITY_SHARED,
            'shared_at' => ! empty($data['soukromy']) ? null : now(),
        ]);

        return $this->hotovo($prostor, ! empty($data['soukromy']) ? 'Soukromý zápis uložen — vidíte ho jen vy' : 'Zapsáno — „'.trim($data['nadpis']).'“', 201);
    }

    public function update(Request $request, string $zapis): JsonResponse
    {
        $prostor = $this->prostor($request);
        $radek = $this->muj($request, $prostor, $zapis);
        $data = $this->over($request);
        $soukromy = ! empty($data['soukromy']);

        $radek->update([
            'title' => trim($data['nadpis']),
            'body' => trim($data['text']),
            'mood' => $data['nalada'] ?? null,
            'entry_date' => $data['datum'] ?? $radek->entry_date,
            'visibility' => $soukromy ? JournalEntry::VISIBILITY_PRIVATE : JournalEntry::VISIBILITY_SHARED,
            // Datum sdílení zůstává z prvního sdílení; po stažení zpět se maže.
            'shared_at' => $soukromy ? null : ($radek->shared_at ?? now()),
        ]);

        return $this->hotovo($prostor, 'Zápis upraven');
    }

    public function destroy(Request $request, string $zapis): JsonResponse
    {
        $prostor = $this->prostor($request);
        $this->muj($request, $prostor, $zapis)->delete();

        return $this->hotovo($prostor, 'Zápis smazán');
    }

    private function prostor(Request $request): GallerySpace
    {
        abort_if((bool) $request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze psát do deníku.');

        return GallerySpace::findOrFail($this->parId($request));
    }

    /** @return array<string, mixed> */
    private function over(Request $request): array
    {
        return $request->validate([
            'nadpis' => ['required', 'string', 'max:180'],
            'text' => ['required', 'string', 'max:50000'],
            'nalada' => ['nullable', 'string', 'in:'.implode(',', self::NALADY)],
            'soukromy' => ['sometimes', 'boolean'],
            // „Dnes" podle Prahy: s UTC by server po půlnoci odmítl dnešní datum.
            'datum' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.Cas::dnes()->toDateString()],
        ]);
    }

    /**
     * Zápis, který volající smí změnit — tedy jeho vlastní.
     *
     * Cizí soukromý zápis se hledá, jako by nebyl (404), sdílený cizí
     * existuje, ale měnit ho nejde (403).
     */
    private function muj(Request $request, GallerySpace $prostor, string $uuid): JournalEntry
    {
        $ja = $request->user();

        $radek = JournalEntry::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->readableBy($ja)
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($radek->isEditableBy($ja), 403, 'Upravovat a mazat může jen autor zápisu.');

        return $radek;
    }

    private function hotovo(GallerySpace $prostor, string $zprava, int $kod = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'zprava' => $zprava] + $this->obsahPoAkci($this->obsah, $prostor), $kod);
    }
}
