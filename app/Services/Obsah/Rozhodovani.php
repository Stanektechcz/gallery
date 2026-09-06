<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Čtyři věci na obrazovkách rozhodování, které aplikace vědět nemůže.
 *
 * Zbytek téhle sekce se počítá — mlčky platná pravidla, kdo mluví, co se
 * rozhodlo samo, zdvih nálady. Tyhle čtyři ne:
 *
 *  - kdo umí přepnout bojler (`BUS`),
 *  - čeho se každý u rozhodnutí bojí (`PM_*`),
 *  - jak dopadly podobné případy v minulosti (`PAST_*`),
 *  - na jakých vstupech rozhodnutí stálo (`REVISIT`).
 *
 * Ani jedno se nedá odvodit z transakcí, kalendáře ani deníku, a tak dokud to
 * dvojice nenapíše, není to nikde. Proto k nim vedou formuláře — a proto jsou
 * jediné čtyři z celé sekce, které formulář mají.
 *
 * `SCEN` a `RITUALS` tady schválně nejsou. Vypadají jako obsah k vyplnění, ale
 * jsou to číselníky zabudovaných funkcí: přepínač scénáře se váže na výpočet
 * v `p60Calc`, rituál na obrazovku aplikace. Nový řádek by byl přepínač,
 * který nic nepřepne.
 */
class Rozhodovani implements PoskytovatelObsahu
{
    public function skupina(): string
    {
        return 'rozhodovani';
    }

    /**
     * Všechno celé.
     *
     * Ukázková obava mezi skutečnými je horší než žádná: pre-mortem stojí na
     * tom, že v tabulce je přesně to, co si ti dva mysleli. A ukázková věc
     * v krytí domácnosti („kde jsou hesla k bance") by tvrdila, že je zapsaná,
     * i kdyby o ní nikdo nikdy nemluvil.
     */
    public function uplne(): array
    {
        return ['BUS', 'PM_DEC', 'PM_MINE', 'PM_THEIRS', 'PM_HIST', 'PAST_DEC', 'PAST_CASES', 'REVISIT'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $jmena = $this->jmena($prostor);

        return array_filter([
            'BUS' => $this->krytiDomacnosti($prostor, $jmena),
            ...$this->premortem($prostor, $jmena),
            ...$this->minulost($prostor),
            'REVISIT' => $this->vstupyRozhodnuti($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Krytí domácnosti: `[{ name, kind, who, crit, doc }]`.
     *
     * `who` je jméno člověka, nebo `oba`. Prototyp to porovnává se jmény členů
     * a `oba` bere jako jediný stav, který není riziko.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function krytiDomacnosti(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_bus_items')) {
            return [];
        }

        return DB::table('couple_bus_items')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('criticality')
            ->orderBy('name')
            ->limit(120)
            ->get()
            ->map(fn (object $v) => [
                'name' => $v->name,
                'kind' => $v->kind,
                'who' => $v->owner_user_id === null ? 'oba' : ($jmena[$v->owner_user_id] ?? 'oba'),
                'crit' => (int) $v->criticality,
                'doc' => (bool) $v->is_documented,
            ])
            ->values()
            ->all();
    }

    /**
     * Pre-mortem: čtyři kolekce z jedněch dat.
     *
     * `PM_DEC` je seznam otevřených rozhodnutí, `PM_MINE` a `PM_THEIRS` obavy
     * obou stran u toho, které je v seznamu první, `PM_HIST` uzavřené případy
     * s tím, čí obava se vyplnila.
     *
     * Proč jen u prvního: prototyp si drží vybrané rozhodnutí ve svém stavu
     * (`pmPick`) a obě strany čte jako plochý seznam. Poslat obavy ke všem
     * rozhodnutím najednou by znamenalo míchat obavy z rekonstrukce s obavami
     * z hypotéky do jedné tabulky.
     *
     * @param  array<int, string>  $jmena
     * @return array<string, list<array<string, mixed>>>
     */
    private function premortem(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_premortems')) {
            return [];
        }

        $vsechna = DB::table('couple_premortems')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        if ($vsechna->isEmpty()) {
            return [];
        }

        $otevrena = $vsechna->whereNull('closed_on')->values();
        $uzavrena = $vsechna->whereNotNull('closed_on')->values();

        // `PM_MINE` je levý sloupec — „moje obavy". První je proto ten, kdo se
        // právě dívá; `jmena()` je tak řadí.
        $poradi = array_keys($jmena);
        $prvni = $poradi[0] ?? null;
        $druhy = $poradi[1] ?? null;

        $rizika = fn (int $id, ?int $kdo) => DB::table('couple_premortem_risks')
            ->where('couple_premortem_id', $id)
            ->when($kdo !== null, fn ($q) => $q->where('author_user_id', $kdo))
            ->orderByDesc('likelihood')
            ->get()
            ->map(fn (object $r) => [
                'risk' => $r->risk,
                'l' => max(1, min(3, (int) $r->likelihood)),
                's' => max(1, min(3, (int) $r->severity)),
                'fix' => (string) ($r->mitigation ?? ''),
            ])
            ->values()
            ->all();

        $vybrane = $otevrena->first();

        return array_filter([
            'PM_DEC' => $otevrena->map(fn (object $p) => [
                'q' => $p->title,
                'when' => (string) ($p->when_label ?? ''),
            ])->values()->all(),

            'PM_MINE' => $vybrane && $prvni !== null ? $rizika((int) $vybrane->id, $prvni) : [],
            'PM_THEIRS' => $vybrane && $druhy !== null ? $rizika((int) $vybrane->id, $druhy) : [],

            'PM_HIST' => $uzavrena->map(function (object $p) use ($prvni, $druhy) {
                $rizika = DB::table('couple_premortem_risks')
                    ->where('couple_premortem_id', $p->id)
                    ->get();

                $vrch = fn (?int $kdo) => $kdo === null
                    ? null
                    : $rizika->where('author_user_id', $kdo)
                        ->sortByDesc(fn (object $r) => $r->likelihood * $r->severity)
                        ->first();

                $moje = $vrch($prvni);
                $jeho = $vrch($druhy);

                // Kdo to trefil: z příznaku u jednotlivých obav, ne z uloženého
                // závěru. Dvě pravdy o téže věci se dřív nebo později rozejdou.
                $trefa = fn (?int $kdo) => $kdo !== null && $rizika
                    ->where('author_user_id', $kdo)
                    ->where('came_true', true)
                    ->isNotEmpty();

                $a = $trefa($prvni);
                $b = $trefa($druhy);

                return [
                    'name' => $p->title,
                    'mine' => $moje?->risk ?? '—',
                    'theirs' => $jeho?->risk ?? '—',
                    'hit' => $a && $b ? 'both' : ($a ? 'mine' : ($b ? 'theirs' : 'none')),
                    'note' => (string) ($p->outcome_note ?? ''),
                ];
            })->values()->all(),
        ], fn (array $v) => $v !== []);
    }

    /**
     * Druhý názor od vlastní minulosti: `PAST_DEC` a `PAST_CASES`.
     *
     * Jedna tabulka, dvě kolekce. Případ s hodnocením je zkušenost, případ bez
     * něj je otázka, která se teprve rozhoduje.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function minulost(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_past_cases')) {
            return [];
        }

        $vse = DB::table('couple_past_cases')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(120)
            ->get();

        if ($vse->isEmpty()) {
            return [];
        }

        $stitky = function (mixed $json): array {
            $pole = json_decode((string) ($json ?? '[]'), true);

            return is_array($pole) ? array_values(array_filter(array_map('strval', $pole))) : [];
        };

        return array_filter([
            'PAST_DEC' => $vse->whereNull('outcome')->map(fn (object $p) => [
                'q' => $p->title,
                'tags' => $stitky($p->tags),
            ])->values()->all(),

            'PAST_CASES' => $vse->whereNotNull('outcome')->map(fn (object $p) => [
                'name' => $p->title,
                'tags' => $stitky($p->tags),
                'out' => max(1, min(5, (int) $p->outcome)),
                'note' => (string) ($p->note ?? ''),
            ])->values()->all(),
        ], fn (array $v) => $v !== []);
    }

    /**
     * Co se od zápisu změnilo: `{ 'uuid-rozhodnutí': [{ what, then, now, dir, note }] }`.
     *
     * Směr se **nečte z databáze, počítá se** z obou hodnot. Uložený sloupec
     * „nahoru/dolů" by se při opravě čísla rozešel se svými hodnotami a šipka
     * by ukazovala opačně, než co je vedle ní napsané.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function vstupyRozhodnuti(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_decision_inputs') || ! Schema::hasTable('couple_decisions')) {
            return [];
        }

        $vstupy = DB::table('couple_decision_inputs')
            ->join('couple_decisions', 'couple_decisions.id', '=', 'couple_decision_inputs.couple_decision_id')
            ->where('couple_decisions.gallery_space_id', $prostor->id)
            ->orderBy('couple_decision_inputs.id')
            ->limit(300)
            ->get([
                'couple_decisions.uuid AS rozhodnuti',
                'couple_decision_inputs.label',
                'couple_decision_inputs.value_then',
                'couple_decision_inputs.value_now',
                'couple_decision_inputs.note',
            ]);

        if ($vstupy->isEmpty()) {
            return [];
        }

        return $vstupy
            ->groupBy('rozhodnuti')
            ->map(fn ($radky) => $radky->map(fn (object $v) => [
                'what' => $v->label,
                'then' => $v->value_then,
                'now' => $v->value_now,
                'dir' => $this->smer($v->value_then, $v->value_now),
                'note' => (string) ($v->note ?? ''),
            ])->values()->all())
            ->all();
    }

    /**
     * Nahoru, nebo dolů — z čísel v obou hodnotách.
     *
     * „13 200 Kč" a „4,1 %" jsou text, protože jednotka patří k hodnotě. Číslo
     * se z nich vytáhne: mezery pryč, desetinná čárka na tečku. Když se v jedné
     * z nich číslo nenajde (třeba „ano" → „ne"), platí `up` — prototyp podle
     * něj vybírá jen ikonu a barvu a nic tvrdit nebude.
     */
    private function smer(string $tehdy, string $ted): string
    {
        $cislo = function (string $text): ?float {
            $ocistene = str_replace([' ', "\u{a0}", "\u{202f}"], '', $text);
            $ocistene = str_replace(',', '.', $ocistene);

            return preg_match('/-?\d+(\.\d+)?/', $ocistene, $shoda) ? (float) $shoda[0] : null;
        };

        $a = $cislo($tehdy);
        $b = $cislo($ted);

        if ($a === null || $b === null) {
            return 'up';
        }

        return $b < $a ? 'down' : 'up';
    }

    /**
     * Členové prostoru, ten kdo se dívá první.
     *
     * `PM_MINE` znamená „moje obavy" a musí to být obavy toho, kdo je zrovna
     * přihlášený. Podle vlastníka to nešlo: v prostoru, který založil ten
     * druhý, se člověk díval na vlastní obavu ve sloupci partnera — tedy přesně
     * na to, čemu se pre-mortem vyhýbá.
     *
     * Prototyp řadí stejně, podle `window.GALERIE_USER`.
     *
     * @return array<int, string>
     */
    private function jmena(GallerySpace $prostor): array
    {
        $lide = $prostor->members()->pluck('users.name', 'users.id')->all();
        $ja = auth()->id();

        if ($ja !== null && array_key_exists($ja, $lide)) {
            $lide = [$ja => $lide[$ja]] + $lide;
        }

        return $lide;
    }
}
