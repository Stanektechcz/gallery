<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kuchařka ve tvaru, ve kterém ji kreslí prototyp.
 *
 * Aplikace má recepty se surovinami, postupem i záznamy z každého vaření —
 * prototyp z toho neukazoval nic. Pět napsaných receptů včetně hodnocení
 * „9/10 · 3 vaření“, které nikdo nikdy nedal.
 *
 * Historie a čísla nad receptem se **počítají z vaření**, ne ukládají: „naposledy
 * 12. 8.“ a „3 vaření“ jsou pohled na `recipe_cooking_sessions`, a druhá kopie
 * by po prvním uvaření lhala.
 */
class Kucharka implements PoskytovatelObsahu
{
    /** Dny v týdnu tak, jak je píše menu. `dayOfWeek` má neděli na nule. */
    private const DNY = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

    public function __construct(private readonly Predpoved $pocasi) {}

    public function skupina(): string
    {
        return 'kucharka';
    }

    /**
     * Recepty i rejstřík přicházejí celé.
     *
     * Nechat vedle skutečných receptů ukázkové znamená nabízet dvojici k večeři
     * něco, co si nikdy nezapsala — a rejstřík by ukazoval na klíče, které
     * neexistují.
     */
    public function uplne(): array
    {
        return ['RECIPES', 'RECIPE_BY_TITLE'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('recipes')) {
            return [];
        }

        $recepty = $this->recepty($prostor);

        return array_filter([
            'RECIPES' => $recepty,
            'RECIPE_BY_TITLE' => $this->rejstrik($recepty),
            // Tytéž recepty a naplánovaná jídla jako seznam.
            'AL' => $this->seznamy($recepty, $prostor),
            // Předpověď na pět dní — podle ní obrazovka řadí návrhy.
            'WEATHER' => $this->pocasi->naPetDni($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Seznamy `AL`: recepty a menu na týden jako `[název, popis, štítek]`.
     *
     * Záložky kuchařky kreslí tytéž věci ještě jednou, jen jako seznam — a ten
     * se bral z `galerie-data.js`. Dvojice tak v jedné záložce viděla své
     * recepty a ve vedlejší cizí.
     *
     * Nákupní seznam mezi nimi není: aplikace pro něj tabulku nemá a vyrobit
     * ho z receptů by znamenalo tvrdit, že něco chybí ve spíži, o které nic
     * nevíme. Zůstává tam, kde dosud byl — ve stavu prohlížeče.
     *
     * @param  array<string, array<string, mixed>>  $recepty
     * @return array<string, list<array<int, ?string>>>
     */
    private function seznamy(array $recepty, GallerySpace $prostor): array
    {
        $doRadku = fn (array $r) => [
            $r['title'],
            trim(implode(' · ', array_filter([$r['time'], $r['kind']]))),
            $r['tag'] ?: null,
        ];

        return array_filter([
            'recipes' => array_map($doRadku, array_values($recepty)),
            'weekMenu' => $this->menu($prostor),
        ], fn (array $v) => $v !== []);
    }

    /**
     * Co je naplánované k jídlu — z `planned_meals`, ne z ukázky.
     *
     * @return list<array<int, ?string>>
     */
    private function menu(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('planned_meals')) {
            return [];
        }

        $od = CarbonImmutable::now()->startOfWeek();

        return DB::table('planned_meals as j')
            ->leftJoin('recipes as r', 'r.id', '=', 'j.recipe_id')
            ->where('j.gallery_space_id', $prostor->id)
            ->where('j.planned_for', '>=', $od)
            ->where('j.planned_for', '<', $od->addDays(14))
            ->orderBy('j.planned_for')
            ->limit(20)
            ->get(['j.planned_for', 'j.meal_type', 'j.status', 'j.notes', 'r.title'])
            ->map(function (object $j) {
                $kdy = CarbonImmutable::parse($j->planned_for);

                return [
                    self::DNY[$kdy->dayOfWeek].' · '.($j->title ?: ($j->notes ?: 'Bez receptu')),
                    trim(implode(' · ', array_filter([
                        $kdy->format('j. n.'),
                        $j->meal_type ?: null,
                    ]))),
                    $j->status === 'cooked' ? 'uvařeno' : 'plán',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Recepty klíčované slugem, jak je prototyp adresuje (`RECIPES.polevka`).
     *
     * @return array<string, array<string, mixed>>
     */
    private function recepty(GallerySpace $prostor): array
    {
        $recepty = DB::table('recipes')
            ->where('gallery_space_id', $prostor->id)
            ->where('status', '!=', 'archived')
            ->orderBy('title')
            ->limit(60)
            ->get();

        if ($recepty->isEmpty()) {
            return [];
        }

        $id = $recepty->pluck('id');
        $suroviny = $this->suroviny($id);
        $postup = $this->postup($id);
        $vareni = $this->vareni($id, $prostor);

        $vysledek = [];

        foreach ($recepty as $r) {
            $klic = $this->klic($r->title, $vysledek);
            $moje = $vareni[$r->id] ?? collect();

            $vysledek[$klic] = [
                'title' => $r->title,
                'kind' => $this->druh($r),
                'time' => $this->cas((int) $r->prep_minutes + (int) $r->cook_minutes),
                'base' => (float) $r->base_servings == (int) $r->base_servings
                    ? (int) $r->base_servings
                    : (float) $r->base_servings,
                'unitLabel' => 'porce',
                'tag' => $this->znacka($r),
                'source' => $this->zdroj($r),
                // Prototyp z toho dělá dotaz do knihovny, ne číslo.
                'photoMatch' => $r->title,
                'desc' => (string) ($r->summary ?: $r->description ?: ''),
                'ing' => ($suroviny[$r->id] ?? collect())->all(),
                'steps' => ($postup[$r->id] ?? collect())->all(),
                'stats' => $this->cisla($r, $moje),
                'history' => $moje->map(fn (object $v) => [
                    CarbonImmutable::parse($v->cooked_at ?? $v->created_at)->format('j. n. Y'),
                    trim($this->poznamkaVareni($v)) ?: 'uvařeno',
                    $v->overall_rating ? $this->hodnoceni((float) $v->overall_rating) : '',
                ])->values()->all(),
                // Co k tomu podat, se v aplikaci nevede; prototyp to snese prázdné.
                'pairing' => [],
            ];
        }

        return $vysledek;
    }

    /**
     * Suroviny: `[množství, jednotka, název, poznámka]`.
     *
     * @return Collection<int, Collection<int, array<int, mixed>>>
     */
    private function suroviny(Collection $id): Collection
    {
        if (! Schema::hasTable('recipe_ingredients')) {
            return collect();
        }

        return DB::table('recipe_ingredients')
            ->whereIn('recipe_id', $id)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('recipe_id')
            ->map(fn (Collection $r) => $r->map(fn (object $s) => [
                // `null`, ne nula: prototyp podle toho pozná surovinu bez
                // množství („sůl dle chuti“) a číslo u ní vůbec nekreslí.
                $s->quantity !== null
                    ? ((float) $s->quantity == (int) $s->quantity ? (int) $s->quantity : (float) $s->quantity)
                    : null,
                (string) ($s->unit ?? ''),
                $s->name.($s->is_optional ? ' (nepovinné)' : ''),
                (string) ($s->preparation ?: ($s->quantity_note ?: '')),
            ])->values());
    }

    /**
     * Postup: `[nadpis kroku, text]`.
     *
     * @return Collection<int, Collection<int, array<int, string>>>
     */
    private function postup(Collection $id): Collection
    {
        if (! Schema::hasTable('recipe_steps')) {
            return collect();
        }

        return DB::table('recipe_steps')
            ->whereIn('recipe_id', $id)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('recipe_id')
            ->map(fn (Collection $r) => $r->values()->map(fn (object $k, int $i) => [
                (string) ($k->title ?: 'Krok '.($i + 1)),
                (string) $k->instruction,
            ]));
    }

    /**
     * Uvařená jídla — od nejnovějšího.
     *
     * @return Collection<int, Collection<int, object>>
     */
    private function vareni(Collection $id, GallerySpace $prostor): Collection
    {
        if (! Schema::hasTable('recipe_cooking_sessions')) {
            return collect();
        }

        return DB::table('recipe_cooking_sessions')
            ->whereIn('recipe_id', $id)
            ->whereNotNull('cooked_at')
            ->orderByDesc('cooked_at')
            ->get()
            ->groupBy('recipe_id');
    }

    /**
     * Čísla nad receptem.
     *
     * Cena za porci se posílá **jen když se doopravdy ví** — z posledního vaření,
     * kde ji někdo zapsal. Odhadnout ji ze surovin by znamenalo vymyslet číslo,
     * podle kterého se dvojice rozhoduje, co uvaří.
     *
     * @param  Collection<int, object>  $vareni
     * @return list<array<int, string>>
     */
    private function cisla(object $r, Collection $vareni): array
    {
        $posledni = $vareni->first();
        $sCenou = $vareni->firstWhere(fn (object $v) => $v->actual_cost !== null);
        $hodnocena = $vareni->filter(fn (object $v) => $v->overall_rating !== null);

        return array_values(array_filter([
            ['Čas přípravy', $this->cas((int) $r->prep_minutes + (int) $r->cook_minutes)],
            $sCenou && $r->base_servings > 0
                ? ['Cena za porci', round((float) $sCenou->actual_cost / (float) $r->base_servings).' Kč']
                : null,
            $posledni
                ? ['Naposledy', CarbonImmutable::parse($posledni->cooked_at)->format('j. n. Y')]
                : null,
            $hodnocena->isNotEmpty()
                ? ['Hodnocení', $this->hodnoceni((float) $hodnocena->avg('overall_rating'))
                    .' · '.$this->pocet($vareni->count(), 'vaření', 'vaření', 'vaření')]
                : ($vareni->isNotEmpty()
                    ? ['Uvařeno', $this->pocet($vareni->count(), 'jednou', 'krát', 'krát')]
                    : null),
        ]));
    }

    /** Co se u toho vaření povedlo nebo změnilo — jednou větou do historie. */
    private function poznamkaVareni(object $v): string
    {
        foreach (['changes_made', 'successes', 'notes', 'improvements'] as $sloupec) {
            $text = trim((string) ($v->{$sloupec} ?? ''));

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    // ——— formát ———

    /**
     * Rejstřík název → klíč. Prototyp si ho staví při načtení z ukázkových dat,
     * takže by po výměně ukazoval na klíče, které už neexistují.
     *
     * @param  array<string, array<string, mixed>>  $recepty
     * @return array<string, string>
     */
    private function rejstrik(array $recepty): array
    {
        $rejstrik = [];

        foreach ($recepty as $klic => $r) {
            $rejstrik[$r['title']] = $klic;
        }

        return $rejstrik;
    }

    /** @param  array<string, mixed>  $uz */
    private function klic(string $nazev, array $uz): string
    {
        $bez = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nazev);
        $zaklad = substr(strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $bez ?: $nazev)) ?: 'recept', 0, 24);
        $klic = $zaklad;
        $poradi = 2;

        while (array_key_exists($klic, $uz)) {
            $klic = $zaklad.$poradi++;
        }

        return $klic;
    }

    /** „Polévka · vegetariánské" — druh a dietní značky pod názvem. */
    private function druh(object $r): string
    {
        $kategorie = match ((string) $r->category) {
            'soup' => 'Polévka',
            'main_course', 'main' => 'Hlavní jídlo',
            'dessert' => 'Dezert',
            'baking' => 'Pečení',
            'salad' => 'Salát',
            'breakfast' => 'Snídaně',
            'side' => 'Příloha',
            'drink' => 'Nápoj',
            default => 'Jídlo',
        };

        $znacky = json_decode((string) ($r->dietary_tags ?? '[]'), true) ?: [];

        return $kategorie.($znacky ? ' · '.implode(', ', array_slice($znacky, 0, 2)) : '');
    }

    private function znacka(object $r): string
    {
        if ($r->is_favorite) {
            return 'oblíbené';
        }

        $prilezitosti = json_decode((string) ($r->occasion_tags ?? '[]'), true) ?: [];

        return $prilezitosti[0] ?? match ((string) $r->difficulty) {
            'hard' => 'na víkend',
            'easy' => 'rychlovka',
            default => '',
        };
    }

    private function zdroj(object $r): string
    {
        $kdo = trim((string) ($r->source_name ?? ''));
        $kdy = CarbonImmutable::parse($r->created_at);

        return trim($kdo.' · '.$this->mesicRok($kdy), ' ·');
    }

    private function mesicRok(CarbonImmutable $kdy): string
    {
        $mesice = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
            'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

        return $mesice[$kdy->month].' '.$kdy->year;
    }

    private function cas(int $minut): string
    {
        if ($minut <= 0) {
            return '—';
        }

        if ($minut < 60) {
            return $minut.' min';
        }

        $hodin = intdiv($minut, 60);
        $zbytek = $minut % 60;

        return $zbytek
            ? $hodin.' h '.$zbytek.' min'
            : $this->pocet($hodin, 'hodina', 'hodiny', 'hodin');
    }

    /** Hodnocení z pěti na desítku — prototyp píše „9/10". */
    private function hodnoceni(float $zPeti): string
    {
        return round($zPeti * 2).'/10';
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $kolik.' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }
}
