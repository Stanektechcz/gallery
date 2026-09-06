<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\Mechanismy;
use App\Services\Obsah\Rozhodovani;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Zápis těch čtyř věcí, které aplikace vědět nemůže.
 *
 * Kdo umí přepnout bojler, čeho se kdo u rozhodnutí bojí, jak dopadl podobný
 * případ před dvěma lety a na jakém čísle rozhodnutí stálo. Nic z toho se nedá
 * odvodit z transakcí ani z kalendáře — a dokud to nikdo nenapíše, obrazovka
 * ukazuje ukázková data cizí dvojice.
 *
 * Vlastní cesta, ne změna stavu: prototyp tyhle kolekce čte z `GalerieData`
 * jako konstanty, ne ze svého stavu. Odpověď proto nese celou skupinu znovu —
 * obrazovka se překresluje z databáze, ne z toho, co si klient myslí, že se
 * stalo.
 */
class ZaznamController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Co se dá zapsat. Neznámý druh je 404, ne tiché nic. */
    public const DRUHY = ['bus', 'bus-zapsano', 'premortem', 'riziko', 'pripad', 'vstup', 'vyrizeno'];

    public function __construct(
        private readonly Rozhodovani $obsah,
        private readonly Mechanismy $mechanismy,
    ) {}

    public function __invoke(Request $request, string $druh): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $zprava = match ($druh) {
            'bus' => $this->krytiDomacnosti($request, $prostor),
            'bus-zapsano' => $this->zapsanoProOba($request, $prostor),
            'premortem' => $this->premortem($request, $prostor),
            'riziko' => $this->riziko($request, $prostor),
            'pripad' => $this->pripad($request, $prostor),
            'vstup' => $this->vstup($request, $prostor),
            'vyrizeno' => $this->vyrizeno($request, $prostor),
            default => abort(404, 'Takový záznam server nezná.'),
        };

        // Kdo to vyřídil patří k mechanismům, ne k rozhodování — odpověď
        // proto nese tu skupinu, kterou zápis opravdu změnil.
        $poskytovatel = $druh === 'vyrizeno' ? $this->mechanismy : $this->obsah;

        return response()->json([
            'zprava' => $zprava,
            'ok' => true,
        ] + $this->obsahPoAkci($poskytovatel, $prostor));
    }

    /**
     * Věc, kterou umí jen jeden.
     *
     * `who` je jméno člena, nebo prázdno pro „oba". Cizí jméno se nedosazuje
     * na `oba` potichu — to by z rizika udělalo krytou věc.
     */
    private function krytiDomacnosti(Request $request, GallerySpace $prostor): string
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'kind' => ['nullable', 'string', 'max:40'],
            'who' => ['nullable', 'string', 'max:120'],
            'crit' => ['nullable', 'integer', 'min:1', 'max:3'],
        ]);

        $kdo = $this->kdo($prostor, $data['who'] ?? null);

        DB::table('couple_bus_items')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'name' => trim($data['name']),
            'kind' => $this->druhVeci($data['kind'] ?? null),
            'owner_user_id' => $kdo,
            'criticality' => (int) ($data['crit'] ?? 2),
            'is_documented' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $kdo === null
            ? 'Zapsáno jako věc, kterou umí oba'
            : 'Zapsáno — zatím to visí na jednom člověku';
    }

    /**
     * „Zapsat pro oba".
     *
     * Přepíná příznak, nemaže řádek: věc zůstává v seznamu i potom, co je
     * zapsaná — jinak by z krytí domácnosti zmizelo právě to, co je v pořádku,
     * a procento by se počítalo z čím dál menšího zbytku.
     */
    private function zapsanoProOba(Request $request, GallerySpace $prostor): string
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
        ]);

        $zmeneno = DB::table('couple_bus_items')
            ->where('gallery_space_id', $prostor->id)
            ->where('name', trim($data['name']))
            ->update(['is_documented' => true, 'updated_at' => now()]);

        if ($zmeneno === 0) {
            throw ValidationException::withMessages([
                'name' => 'Tahle věc v krytí domácnosti není.',
            ]);
        }

        return 'Zapsáno — teď je to na dvou místech';
    }

    /** Rozhodnutí, ke kterému si oba předem napíšou, co se pokazí. */
    private function premortem(Request $request, GallerySpace $prostor): string
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'when' => ['nullable', 'string', 'max:80'],
        ]);

        DB::table('couple_premortems')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'title' => trim($data['title']),
            'when_label' => $this->text($data['when'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'Rozhodnutí založeno — teď každý zvlášť napíše, co se může pokazit';
    }

    /**
     * Jedna obava jednoho z nich.
     *
     * Připíná se k **prvnímu otevřenému** rozhodnutí, protože to je i to, které
     * obrazovka ukazuje. Bez jediného otevřeného není kam psát a je poctivější
     * to říct, než obavu tiše zahodit.
     */
    private function riziko(Request $request, GallerySpace $prostor): string
    {
        $data = $request->validate([
            'risk' => ['required', 'string', 'max:180'],
            'l' => ['nullable', 'integer', 'min:1', 'max:3'],
            's' => ['nullable', 'integer', 'min:1', 'max:3'],
            'fix' => ['nullable', 'string', 'max:400'],
        ]);

        $rozhodnuti = DB::table('couple_premortems')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('closed_on')
            ->orderByDesc('created_at')
            ->first();

        if ($rozhodnuti === null) {
            throw ValidationException::withMessages([
                'risk' => 'Nejdřív založte rozhodnutí — obava se váže k němu, ne k ničemu.',
            ]);
        }

        DB::table('couple_premortem_risks')->insert([
            'uuid' => (string) Str::uuid(),
            'couple_premortem_id' => $rozhodnuti->id,
            'author_user_id' => $request->user()?->id,
            'risk' => trim($data['risk']),
            'likelihood' => (int) ($data['l'] ?? 2),
            'severity' => (int) ($data['s'] ?? 2),
            'mitigation' => $this->text($data['fix'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'Obava zapsaná k „'.$rozhodnuti->title.'“';
    }

    /**
     * Případ z minulosti, nebo otázka, která se teprve rozhoduje.
     *
     * Rozdíl je jediný: hodnocení. S ním je to zkušenost, bez něj otázka —
     * a právě proto je to jedna tabulka. Přepsat otázku na případ pak znamená
     * doplnit číslo, ne založit druhý řádek o téže věci.
     */
    private function pripad(Request $request, GallerySpace $prostor): string
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'tags' => ['nullable', 'string', 'max:200'],
            'out' => ['nullable', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:600'],
        ]);

        $stitky = array_values(array_filter(array_map(
            fn (string $s) => trim($s),
            preg_split('/[,;]+/', (string) ($data['tags'] ?? '')) ?: [],
        ), fn (string $s) => $s !== ''));

        DB::table('couple_past_cases')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'title' => trim($data['title']),
            'tags' => json_encode($stitky, JSON_UNESCAPED_UNICODE),
            'outcome' => isset($data['out']) ? (int) $data['out'] : null,
            'note' => $this->text($data['note'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return isset($data['out'])
            ? 'Zkušenost zapsaná — najde se u podobných otázek podle štítků'
            : 'Otázka zapsaná — aplikace k ní dohledá vaše vlastní případy';
    }

    /**
     * Vstup, na kterém rozhodnutí stálo.
     *
     * Revize se pak nenabízí podle data, ale podle toho, že se tohle číslo
     * pohnulo. Rozhodnutí se hledá podle uuid v témž prostoru — cizí by šlo
     * doplnit vstupem odjinud.
     */
    private function vstup(Request $request, GallerySpace $prostor): string
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:120'],
            'then' => ['required', 'string', 'max:80'],
            'now' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:400'],
        ]);

        $rozhodnuti = DB::table('couple_decisions')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $data['decision'])
            ->first();

        if ($rozhodnuti === null) {
            throw ValidationException::withMessages([
                'decision' => 'Takové rozhodnutí v paměti není.',
            ]);
        }

        DB::table('couple_decision_inputs')->insert([
            'uuid' => (string) Str::uuid(),
            'couple_decision_id' => $rozhodnuti->id,
            'label' => trim($data['label']),
            'value_then' => trim($data['then']),
            'value_now' => trim($data['now']),
            'note' => $this->text($data['note'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'Vstup sledovaný — revize se ozve, až se pohne';
    }

    /**
     * Kdo to vyřídil.
     *
     * Jeden protokol pro dvě obrazovky: „neviditelná práce" je jeho část
     * navázaná na kontakt s rodinou, „kdo mluví za koho" je týž protokol
     * seskupený po oblastech.
     *
     * `asked` je jediná věc, kterou nejde odvodit — jestli se ten, kdo to
     * vyřizoval, předem zeptal druhého. Bez ní se nedá říct, kde se rozhoduje
     * za oba, aniž by o tom druhý věděl.
     */
    private function vyrizeno(Request $request, GallerySpace $prostor): string
    {
        $data = $request->validate([
            'area' => ['required', 'string', 'max:120'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'asked' => ['nullable', 'boolean'],
            'contact' => ['nullable', 'string', 'max:180'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $kontakt = null;
        $oblast = trim($data['area']);

        if (($data['contact'] ?? '') !== '' && Schema::hasTable('couple_family_contacts')) {
            $kontakt = DB::table('couple_family_contacts')
                ->where('gallery_space_id', $prostor->id)
                ->where('name', trim((string) $data['contact']))
                ->first();

            if ($kontakt === null) {
                throw ValidationException::withMessages([
                    'contact' => 'Takový kontakt v rotaci rodiny není.',
                ]);
            }

            // U rodiny je oblast dané jméno; jinak by se každý kontakt počítal
            // jako vlastní kanál a „kdo mluví za koho" by se rozpadlo na deset
            // řádků po jednom.
            $oblast = 'Rodina — '.$kontakt->name;
        }

        DB::table('couple_outreach_log')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'couple_family_contact_id' => $kontakt?->id,
            'area' => $oblast,
            'by_user_id' => $request->user()?->id,
            'happened_on' => now()->toDateString(),
            'minutes' => $data['minutes'] ?? null,
            'asked_partner' => (bool) ($data['asked'] ?? false),
            'note' => $this->text($data['note'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rotace kontaktu si drží „naposledy" u sebe; bez toho by lhůta běžela
        // dál, i když se právě zavolalo.
        if ($kontakt !== null) {
            DB::table('couple_family_contacts')
                ->where('id', $kontakt->id)
                ->update([
                    'last_contact_on' => now()->toDateString(),
                    'last_contact_by' => $request->user()?->id,
                    'updated_at' => now(),
                ]);
        }

        return 'Zapsáno — '.$oblast;
    }

    /**
     * Jméno člena na jeho id. Prázdno i neznámé jméno znamená „oba".
     *
     * Neznámé jméno je tady schválně měkké: obrazovka nabízí jen jména členů,
     * takže cizí sem přijde jen z ručně poskládaného požadavku.
     */
    private function kdo(GallerySpace $prostor, ?string $jmeno): ?int
    {
        $jmeno = trim((string) $jmeno);

        if ($jmeno === '' || mb_strtolower($jmeno) === 'oba') {
            return null;
        }

        $lide = $prostor->members()->pluck('users.id', 'users.name')->all();

        return $lide[$jmeno] ?? null;
    }

    /** Typ věci v krytí domácnosti. Prototyp barví jen tyhle tři. */
    private function druhVeci(?string $druh): string
    {
        $druh = mb_strtolower(trim((string) $druh));

        return in_array($druh, ['heslo', 'kontakt', 'postup'], true) ? $druh : 'postup';
    }

    /** Prázdný text je `null`, ne prázdný řetězec — ať se v tabulce pozná. */
    private function text(?string $hodnota): ?string
    {
        $text = trim((string) $hodnota);

        return $text === '' ? null : $text;
    }
}
