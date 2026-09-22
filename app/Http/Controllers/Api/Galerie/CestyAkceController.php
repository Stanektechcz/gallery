<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\Cesty;
use App\Services\Planning\TravelInboxService;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cesty z galerie: výdaj cesty a bod programu dne.
 *
 * „Přidat výdaj" v detailu cesty i v cestovním režimu hlásilo „přidáno do
 * rozpočtu cesty" a zapsalo řádek jen do stavu obrazovky — fond cesty, útrata
 * dne ani statistika o něm nevěděly. „Přidat do itineráře" bylo „zatím
 * neumíme". Cesty přitom mají tabulky (`trip_expenses`, `trip_days`,
 * `trip_activities`), ze kterých obrazovky čtou.
 */
class CestyAkceController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Kategorie, jak je obrazovka píše, na kategorie cesty v databázi. */
    private const KATEGORIE = [
        'doprava' => 'transport', 'nocleh' => 'accommodation', 'ubytování' => 'accommodation',
        'jídlo' => 'food', 'vstupy a doprava' => 'activities', 'vstupy' => 'activities',
        'nákupy' => 'shopping', 'rezerva' => 'reserve', 'letenky' => 'flights',
    ];

    public function __construct(private readonly Cesty $obsah) {}

    public function vydaj(Request $request, int $cesta): JsonResponse
    {
        [$prostor, $radek] = $this->cesta($request, $cesta);
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:255'],
            'castka' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'kategorie' => ['nullable', 'string', 'max:60'],
            'zaplatil' => ['nullable', 'string', 'max:120'],
        ]);

        DB::table('trip_expenses')->insert([
            'trip_id' => $radek->id,
            'created_by' => $request->user()->id,
            'title' => trim($data['nazev']),
            'category' => self::KATEGORIE[mb_strtolower(trim((string) ($data['kategorie'] ?? '')))] ?? 'other',
            'amount' => round((float) $data['castka'], 2),
            'currency' => Tabulky::sloupec('trips', 'currency') && ! empty($radek->currency) ? $radek->currency : 'CZK',
            'paid_by' => $data['zaplatil'] ?? $request->user()->name,
            'state' => 'actual',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->hotovo($prostor, 'Výdaj zapsán k cestě „'.$radek->name.'“', 201);
    }

    /**
     * Bod programu do dne cesty.
     *
     * Den chodí jako pořadí (0 = první den), jak ho obrazovka kreslí. Když
     * cesta dny ještě nemá, den se založí podle data začátku.
     */
    public function program(Request $request, int $cesta): JsonResponse
    {
        [$prostor, $radek] = $this->cesta($request, $cesta);
        $data = $request->validate([
            'den' => ['required', 'integer', 'min:0', 'max:365'],
            'nazev' => ['required', 'string', 'max:255'],
            'cas' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'misto' => ['nullable', 'string', 'max:255'],
        ]);

        $zacatek = CarbonImmutable::parse($radek->start_date);
        $konec = CarbonImmutable::parse($radek->end_date);
        $datum = $zacatek->addDays((int) $data['den']);

        if ($datum->gt($konec)) {
            return response()->json(['ok' => false, 'zprava' => 'Cesta končí '.$konec->format('j. n.').' — takový den nemá.'], 422);
        }

        DB::transaction(function () use ($radek, $datum, $data, $request) {
            $den = DB::table('trip_days')->where('trip_id', $radek->id)->whereDate('date', $datum->toDateString())->first();

            $denId = $den?->id ?? DB::table('trip_days')->insertGetId([
                'trip_id' => $radek->id,
                'date' => $datum->toDateString(),
                'sort_order' => (int) $datum->diffInDays(CarbonImmutable::parse($radek->start_date)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('trip_activities')->insert([
                'trip_day_id' => $denId,
                'created_by' => $request->user()->id,
                'type' => 'activity',
                'title' => trim($data['nazev']),
                'starts_at' => ! empty($data['cas']) ? $data['cas'].':00' : null,
                'place_name' => $data['misto'] ?? null,
                'status' => 'planned',
                'sort_order' => (int) DB::table('trip_activities')->where('trip_day_id', $denId)->max('sort_order') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->hotovo($prostor, '„'.trim($data['nazev']).'“ přidáno do programu '.$datum->format('j. n.'), 201);
    }

    /**
     * Bod programu splněný — nebo zpátky.
     *
     * Telefon si „Splněno" pamatoval jen u sebe: druhý z dvojice ani
     * počítač o tom nevěděli a program cesty pořád ukazoval jako neudělané.
     */
    /**
     * Rezervace, odkaz nebo nápad do travel inboxu.
     *
     * Travel inbox v galerii šlo jen vyřizovat — přidat do něj nešlo nic
     * a prázdný stav sliboval, že se sem věci „přesypou z e-mailu". Zápis
     * jde přes TravelInboxService jako ve starém rozhraní.
     */
    public function doInboxu(Request $request, TravelInboxService $inbox): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:255'],
            'odkaz' => ['nullable', 'url:https', 'max:2048'],
            'poznamka' => ['nullable', 'string', 'max:5000'],
            'druh' => ['nullable', 'in:rezervace,odkaz,napad'],
        ], [
            'odkaz.url' => 'Odkaz musí začínat https://.',
        ]);

        $inbox->create($prostor->id, (int) $request->user()->id, [
            'title' => trim($data['nazev']),
            'source_url' => $data['odkaz'] ?? null,
            'notes' => $data['poznamka'] ?? null,
            'kind' => match ($data['druh'] ?? null) {
                'rezervace' => 'reservation',
                'odkaz' => 'link',
                default => ! empty($data['odkaz']) ? 'link' : 'idea',
            },
        ], 'manual');

        return $this->hotovo($prostor, '„'.trim($data['nazev']).'“ je v travel inboxu — zařadíte ho, až bude jasné kam', 201);
    }

    /**
     * Jízdenka nebo trasa, kterou si dvojice uloží.
     *
     * „Přidat jízdenku" dřív založilo řádek „Nová jízdenka — doplňte trasu
     * a datum", jenže doplnit nebylo kde. Teď se název a trasa zapíšou hned.
     */
    public function jizdenka(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'odkud' => ['nullable', 'string', 'max:255'],
            'kam' => ['nullable', 'string', 'max:255'],
        ]);

        $nazev = trim($data['nazev']);
        $existuje = DB::table('saved_transport_routes')
            ->where('gallery_space_id', $prostor->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($nazev)])
            ->exists();

        abort_if($existuje, 422, '„'.$nazev.'“ už mezi jízdenkami je.');

        DB::table('saved_transport_routes')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $request->user()->id,
            'name' => $nazev,
            'origin' => trim((string) ($data['odkud'] ?? '')),
            'destination' => trim((string) ($data['kam'] ?? '')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->hotovo($prostor, 'Jízdenka „'.$nazev.'“ je uložená', 201);
    }

    public function smazatJizdenku(Request $request, string $uuid): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $nazev = DB::table('saved_transport_routes')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $uuid)
            ->value('name');

        abort_if($nazev === null, 404);

        DB::table('saved_transport_routes')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $uuid)
            ->delete();

        return $this->hotovo($prostor, 'Smazáno — '.$nazev);
    }

    public function splneno(Request $request, int $aktivita): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate(['hotovo' => ['required', 'boolean']]);

        $radek = DB::table('trip_activities as a')
            ->join('trip_days as d', 'd.id', '=', 'a.trip_day_id')
            ->join('trips as t', 't.id', '=', 'd.trip_id')
            ->where('t.gallery_space_id', $prostor->id)
            ->where('a.id', $aktivita)
            ->first(['a.id', 'a.title']);

        abort_if($radek === null, 404, 'Takový bod programu tu není.');

        DB::table('trip_activities')->where('id', $radek->id)->update([
            'status' => $data['hotovo'] ? 'done' : 'planned',
            'updated_at' => now(),
        ]);

        return $this->hotovo($prostor, ($data['hotovo'] ? 'Splněno — ' : 'Vráceno — ').$radek->title);
    }

    /** Posunout bod programu (výchozí o hodinu) — v rámci dne, přes půlnoc ne. */
    public function posunout(Request $request, int $aktivita): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $data = $request->validate(['minut' => ['sometimes', 'integer', 'between:-720,720']]);

        $radek = DB::table('trip_activities as a')
            ->join('trip_days as d', 'd.id', '=', 'a.trip_day_id')
            ->join('trips as t', 't.id', '=', 'd.trip_id')
            ->where('t.gallery_space_id', $prostor->id)
            ->where('a.id', $aktivita)
            ->first(['a.id', 'a.title', 'a.starts_at', 'a.ends_at']);

        abort_if($radek === null, 404, 'Takový bod programu tu není.');

        if (! $radek->starts_at) {
            return response()->json(['ok' => false, 'zprava' => '„'.$radek->title.'“ nemá čas — není od čeho posouvat.'], 422);
        }

        $minut = (int) ($data['minut'] ?? 60);
        $zacatek = CarbonImmutable::parse('2000-01-01 '.$radek->starts_at);
        $novy = $zacatek->addMinutes($minut);

        if (! $novy->isSameDay($zacatek)) {
            return response()->json(['ok' => false, 'zprava' => 'Posunout přes půlnoc nejde — přesuňte bod do jiného dne.'], 422);
        }

        DB::table('trip_activities')->where('id', $radek->id)->update([
            'starts_at' => $novy->format('H:i:s'),
            'ends_at' => $radek->ends_at ? CarbonImmutable::parse('2000-01-01 '.$radek->ends_at)->addMinutes($minut)->format('H:i:s') : null,
            'updated_at' => now(),
        ]);

        return $this->hotovo($prostor, '„'.$radek->title.'“ posunuto na '.$novy->format('G:i'));
    }

    /** @return array{0: GallerySpace, 1: object} */
    private function cesta(Request $request, int $id): array
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = DB::table('trips')->where('gallery_space_id', $prostor->id)->where('id', $id)->first();

        abort_if($radek === null, 404, 'Taková cesta tu není.');

        return [$prostor, $radek];
    }

    private function hotovo(GallerySpace $prostor, string $zprava, int $kod = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'zprava' => $zprava] + $this->obsahPoAkci($this->obsah, $prostor), $kod);
    }
}
