<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Účet radosti: co doopravdy vyrobilo dobré dny — a za kolik.
 *
 * Obrazovka o sobě říká: „Zdvih nálady se bere z korelací v sekci Nálada dvou,
 * útrata z transakcí, hodiny z kalendáře. Nic se nehodnotí dojmem." Přesně
 * tak se to počítá — z těch tří tabulek a z ničeho jiného.
 *
 * Chyběl k tomu jediný údaj: **co to za společnou věc vlastně bylo**. Typ
 * události (`event`, `birthday`) popisuje, jak se chová v kalendáři, ne jestli
 * to byla snídaně mimo domov nebo návštěva u rodiny. Proto `activity_kind`.
 *
 * Útrata se počítá jen ze **dnů, kdy se dělo jen tohle jedno**. Den, ve kterém
 * je randíčko i velký nákup do bytu, neumí aplikace rozdělit — a přiřadit
 * celou útratu oběma by znamenalo tvrdit, že randíčko stálo čtyři tisíce.
 */
class UcetRadosti
{
    /** Kolik měsíců zpátky se počítá. */
    private const MESICU = 12;

    /** Kolikrát se to musí stát, aby se z toho dal číst zdvih nálady. */
    private const NEJMENE = 3;

    /**
     * @return list<array{name: string, cost: int, hours: float, lift: float, n: int}>
     */
    public function spocitej(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('calendar_events') || ! Schema::hasTable('wellbeing_moods')) {
            return [];
        }

        $od = CarbonImmutable::now()->subMonths(self::MESICU)->startOfDay();

        $udalosti = DB::table('calendar_events')
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('activity_kind')
            ->where('starts_at', '>=', $od)
            ->where('starts_at', '<', CarbonImmutable::now())
            ->limit(2000)
            ->get(['activity_kind', 'starts_at', 'ends_at', 'all_day']);

        if ($udalosti->isEmpty()) {
            return [];
        }

        // Den → druhy činností, které se ten den staly.
        $dny = [];

        foreach ($udalosti as $u) {
            $den = CarbonImmutable::parse($u->starts_at)->toDateString();
            $dny[$den][$u->activity_kind] = true;
        }

        $nalady = $this->naladyPoDnech($prostor, $od);
        $prumer = $nalady === [] ? null : array_sum($nalady) / count($nalady);
        $utrata = $this->utrataPoDnech($prostor, $od);

        $skupiny = [];

        foreach ($udalosti as $u) {
            $druh = $u->activity_kind;
            $den = CarbonImmutable::parse($u->starts_at)->toDateString();

            $skupiny[$druh]['n'] = ($skupiny[$druh]['n'] ?? 0) + 1;
            $skupiny[$druh]['hodin'] = ($skupiny[$druh]['hodin'] ?? 0) + $this->hodin($u);
            $skupiny[$druh]['dny'][$den] = true;
        }

        $radky = [];

        foreach ($skupiny as $druh => $data) {
            $pocet = (int) $data['n'];

            if ($pocet < self::NEJMENE) {
                continue;
            }

            $dnyDruhu = array_keys($data['dny']);
            $samotne = array_values(array_filter($dnyDruhu, fn (string $d) => count($dny[$d]) === 1));

            $radky[] = [
                'name' => $druh,
                'cost' => $this->prumernaUtrata($samotne, $utrata),
                'hours' => round($data['hodin'] / $pocet, 1),
                'lift' => $this->zdvih($dnyDruhu, $nalady, $prumer),
                'n' => $pocet,
            ];
        }

        usort($radky, fn (array $a, array $b) => $b['n'] <=> $a['n']);

        return array_slice($radky, 0, 12);
    }

    /**
     * Kolik hodin ta věc zabrala.
     *
     * Celodenní událost se počítá jako osm hodin — ne dvacet čtyři: „víkend
     * někde jinde" nikdo nestráví ve spánku a v grafu by to přebilo všechno
     * ostatní. Událost bez konce se počítá jako hodina a půl, což je délka,
     * kterou kalendář sám nabízí jako výchozí.
     */
    private function hodin(object $u): float
    {
        if ($u->all_day) {
            return 8.0;
        }

        if (! $u->ends_at) {
            return 1.5;
        }

        $od = CarbonImmutable::parse($u->starts_at);
        $do = CarbonImmutable::parse($u->ends_at);

        return max(0.25, min(16.0, round($od->diffInMinutes($do) / 60, 2)));
    }

    /**
     * Zdvih nálady: průměr ve dnech, kdy se to dělo, proti průměru všech dnů.
     *
     * Bez zapsané nálady se vrací nula — ne odhad. Nula znamená „na náladě to
     * nebylo poznat", což je něco jiného než „nevíme", ale obrazovka pro to
     * druhé místo nemá a vymýšlet číslo by bylo horší.
     *
     * @param  array<string, float>  $nalady
     * @param  list<string>  $dny
     */
    private function zdvih(array $dny, array $nalady, ?float $prumer): float
    {
        if ($prumer === null) {
            return 0.0;
        }

        $hodnoty = array_values(array_filter(
            array_map(fn (string $d) => $nalady[$d] ?? null, $dny),
            fn (?float $v) => $v !== null,
        ));

        if ($hodnoty === []) {
            return 0.0;
        }

        return round(array_sum($hodnoty) / count($hodnoty) - $prumer, 1);
    }

    /**
     * Průměrná útrata ve dnech, kdy se dělo jen tohle.
     *
     * @param  list<string>  $dny
     * @param  array<string, float>  $utrata
     */
    private function prumernaUtrata(array $dny, array $utrata): int
    {
        if ($dny === []) {
            return 0;
        }

        $soucet = 0.0;

        foreach ($dny as $den) {
            $soucet += $utrata[$den] ?? 0.0;
        }

        return (int) round($soucet / count($dny));
    }

    /**
     * Nálada po dnech — průměr obou, když ji zapsali oba.
     *
     * @return array<string, float>
     */
    private function naladyPoDnech(GallerySpace $prostor, CarbonImmutable $od): array
    {
        $radky = DB::table('wellbeing_moods')
            ->where('gallery_space_id', $prostor->id)
            ->where('day', '>=', $od->toDateString())
            ->limit(3000)
            ->get(['day', 'value']);

        $podleDne = [];

        foreach ($radky as $r) {
            $den = CarbonImmutable::parse($r->day)->toDateString();
            $podleDne[$den][] = (float) $r->value;
        }

        return array_map(fn (array $v) => array_sum($v) / count($v), $podleDne);
    }

    /**
     * Útrata po dnech.
     *
     * @return array<string, float>
     */
    private function utrataPoDnech(GallerySpace $prostor, CarbonImmutable $od): array
    {
        if (! Schema::hasTable('transactions')) {
            return [];
        }

        $radky = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->where('type', 'expense')
            ->where('occurred_at', '>=', $od)
            ->limit(5000)
            ->get(['occurred_at', 'amount_from']);

        $podleDne = [];

        foreach ($radky as $r) {
            $den = CarbonImmutable::parse($r->occurred_at)->toDateString();
            $podleDne[$den] = ($podleDne[$den] ?? 0.0) + abs((float) $r->amount_from);
        }

        return $podleDne;
    }
}
