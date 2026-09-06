<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klid a pohoda: mapa energie, rozpočet pozornosti, co čeká na okno
 * a otázka na dva.
 *
 * Kapacita týdne ví, **kdy má dvojice volno** — to jde vyčíst z kalendáře.
 * Tohle je druhá vrstva: kdy má **sílu**. To z ničeho vyčíst nejde, musí to
 * někdo říct, a proto na to jsou vlastní tabulky.
 *
 * Rozpočet pozornosti se dělí na dvě půlky, které se nesmí smíchat: `want`
 * je přání dvojice a je uložené, `real` je měřené z toho, co je zapsané
 * jinde. Uložený `real` by po prvním úklidu ukazoval loňský stav.
 */
class Klid implements PoskytovatelObsahu
{
    /** Kolik dní zpátky se měří skutečně strávený čas. */
    private const MERENO_DNI = 30;

    /**
     * Rozpočet pozornosti, jak ho obrazovka nabídne, než si ho dvojice
     * přepíše. Poslední pole říká, co tu položku měří.
     *
     * @var list<array{0: string, 1: string, 2: int, 3: string, 4: string, 5: ?string, 6: ?string}>
     */
    private const POZORNOST = [
        ['prace', 'Práce', 30, 'calendar', 'Ze zapsaného času v kalendáři.', null, null],
        ['my', 'My dva', 20, 'dates', 'Randíčka, deník, večery spolu.', 'x-randicka', null],
        ['rodina', 'Rodina a rodiče', 16, 'none', 'Rotace kontaktu s rodinou.', 'x-domacnost', 'fam'],
        ['byt', 'Byt a provoz', 12, 'chores', 'Dělba práce, lhůty, inventář.', 'x-domacnost', 'chores'],
        ['penize', 'Peníze a papíry', 8, 'money', 'Transakce, rozpočty, splátky.', 'x-finance', null],
        ['sam', 'Každý sám za sebe', 14, 'none', 'Nejmenší položka — a nikdo ji nehájí.', null, null],
    ];

    public function __construct(private readonly UcetRadosti $radost) {}

    public function skupina(): string
    {
        return 'klid';
    }

    /**
     * Všechno přichází celé.
     *
     * Ukázková položka rozpočtu pozornosti vedle skutečných by znamenala, že
     * součet nesedí a obrazovka hlásí přetížení, které si dvojice nenastavila.
     */
    public function uplne(): array
    {
        return ['KL_EN', 'KL_ATTN', 'KL_TASKS', 'KL_ASK_LOG'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'KL_EN' => $this->energie($prostor),
            'KL_ATTN' => $this->pozornost($prostor),
            'KL_TASKS' => $this->cekaNaOkno($prostor),
            'KL_ASK_LOG' => $this->otazky($prostor),
            'KL_ASK_NOW' => $this->dnesniOtazka($prostor),
            'SOLO' => $this->casProSebe($prostor),
            // Co doopravdy vyrobilo dobré dny — a za kolik.
            'JOY' => $this->radost->spocitej($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Čas pro sebe: `[{ week, a, k }]`, šest týdnů zpátky.
     *
     * Počítá se z událostí v kalendáři, u kterých je **jen jeden z dvojice**.
     * Není to všechen čas o samotě — jen ten, který si někdo zapsal; a přesně
     * to je na tom podstatné: co se nezapíše, se taky nenaplánuje.
     *
     * @return list<array<string, mixed>>
     */
    private function casProSebe(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('calendar_events') || ! Schema::hasTable('event_participants')) {
            return [];
        }

        [$prvni, $druhy] = array_pad(array_keys($this->jmena($prostor)), 2, null);

        if ($prvni === null || $druhy === null) {
            return [];
        }

        $od = CarbonImmutable::now()->startOfWeek()->subWeeks(5);

        $udalosti = DB::table('calendar_events as u')
            ->join('event_participants as ucast', 'ucast.event_id', '=', 'u.id')
            ->where('u.gallery_space_id', $prostor->id)
            ->where('u.starts_at', '>=', $od)
            ->get(['u.id', 'u.starts_at', 'ucast.user_id'])
            ->groupBy('id');

        if ($udalosti->isEmpty()) {
            return [];
        }

        $tydny = [];

        foreach ($udalosti as $ucastnici) {
            // Událost, kde jsou oba, není čas pro sebe.
            if ($ucastnici->pluck('user_id')->unique()->count() !== 1) {
                continue;
            }

            $kdo = (int) $ucastnici->first()->user_id;
            $tyden = CarbonImmutable::parse($ucastnici->first()->starts_at)->startOfWeek();
            $klic = $tyden->format('Y-m-d');

            $tydny[$klic] ??= ['week' => $tyden->format('j. n.'), 'a' => 0, 'k' => 0];

            if ($kdo === $prvni) {
                $tydny[$klic]['a']++;
            } elseif ($kdo === $druhy) {
                $tydny[$klic]['k']++;
            }
        }

        if (! $tydny) {
            return [];
        }

        ksort($tydny);

        return array_values($tydny);
    }

    /**
     * Dnešní otázka: co na ni napsal ten druhý.
     *
     * Ukázka měla odpověď druhého napsanou v katalogu otázek, takže
     * „odemknout obě naráz" odemklo větu, kterou nikdo neřekl.
     *
     * Odpověď druhého se vydá, **až když odpověděl i ten, kdo se dívá** —
     * v tom je celý smysl té obrazovky: nedá se odpovídat podle něj.
     *
     * @return array<string, mixed>
     */
    private function dnesniOtazka(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('wellbeing_answers')) {
            return [];
        }

        $ja = auth()->id();
        $dnes = CarbonImmutable::now()->toDateString();

        $dnesni = DB::table('wellbeing_answers')
            ->where('gallery_space_id', $prostor->id)
            ->where('asked_on', $dnes)
            ->get();

        if ($dnesni->isEmpty()) {
            return [];
        }

        $moje = $dnesni->firstWhere('user_id', $ja);

        return array_filter([
            'q' => (string) $dnesni->first()->question,
            'mine' => (string) ($moje->answer ?? ''),
            'done' => $moje !== null,
            'other' => $moje === null
                ? ''
                : (string) ($dnesni->first(fn (object $o) => (int) $o->user_id !== (int) $ja)->answer ?? ''),
        ], fn ($v) => $v !== '' && $v !== false);
    }

    /**
     * Mapa energie: `{ jméno: ['221', …×7] }`.
     *
     * Sedm dní od pondělí, tři části dne, tři úrovně. Řetězec, protože přesně
     * tak ho obrazovka čte — `en[jméno][den][část]`.
     *
     * Posílá se, jen když si ji aspoň jeden z dvojice založil. Prázdná mřížka
     * samých nul by tvrdila, že nikdo nemá sílu na nic.
     *
     * @return array<string, list<string>>
     */
    private function energie(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('wellbeing_energy')) {
            return [];
        }

        $zapsane = DB::table('wellbeing_energy')
            ->where('gallery_space_id', $prostor->id)
            ->get(['user_id', 'weekday', 'slot', 'level']);

        if ($zapsane->isEmpty()) {
            return [];
        }

        $podleLidi = $zapsane->groupBy('user_id');
        $mapa = [];

        foreach ($this->jmena($prostor) as $id => $jmeno) {
            $bunky = ($podleLidi[$id] ?? collect())
                ->keyBy(fn (object $b) => $b->weekday.'-'.$b->slot);

            $mapa[$jmeno] = collect(range(0, 6))
                ->map(fn (int $den) => collect(range(0, 2))
                    ->map(fn (int $cast) => (string) (int) ($bunky[$den.'-'.$cast]->level ?? 0))
                    ->implode(''))
                ->all();
        }

        return $mapa;
    }

    /**
     * Rozpočet pozornosti: `[{ key, name, want, real, note, route, tab }]`.
     *
     * `want` je přání dvojice, `real` se měří. Co nic neměří, má `real` nula
     * a poznámku, která to říká — nula z ničeho je pravda, dopočítaný odhad
     * by byl výtka za čas, který nikdo nesledoval.
     *
     * @return list<array<string, mixed>>
     */
    private function pozornost(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('wellbeing_attention')) {
            return [];
        }

        $ulozene = DB::table('wellbeing_attention')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Bez vlastního nastavení platí to, co obrazovka nabízí — jsou to
        // pojmenované oblasti života, ne data dvojice.
        $radky = $ulozene->isNotEmpty()
            ? $ulozene->map(fn (object $r) => [
                'key' => $r->key, 'name' => $r->name, 'want' => (int) $r->want,
                'measure' => (string) $r->measure, 'note' => (string) ($r->note ?? ''),
                'route' => $r->route, 'tab' => $r->tab,
            ])->all()
            : array_map(fn (array $p) => [
                'key' => $p[0], 'name' => $p[1], 'want' => $p[2],
                'measure' => $p[3], 'note' => $p[4], 'route' => $p[5], 'tab' => $p[6],
            ], self::POZORNOST);

        $minuty = $this->zmerenyCas($prostor);
        $celkem = max(1, array_sum($minuty));

        return array_map(function (array $r) use ($minuty, $celkem) {
            $merene = $minuty[$r['measure']] ?? null;

            return [
                'key' => $r['key'],
                'name' => $r['name'],
                'want' => $r['want'],
                'real' => $merene === null ? 0 : (int) round($merene / $celkem * 100),
                'note' => $merene === null
                    ? 'Tohle zatím nic neměří — číslo vlevo je jen vaše přání.'
                    : $r['note'],
                'route' => $r['route'],
                'tab' => $r['tab'],
            ];
        }, $radky);
    }

    /**
     * Kolik minut za poslední měsíc padlo na co — z toho, co je zapsané.
     *
     * @return array<string, float>
     */
    private function zmerenyCas(GallerySpace $prostor): array
    {
        $od = CarbonImmutable::now()->subDays(self::MERENO_DNI);
        $minuty = [];

        if (Schema::hasTable('house_chore_log')) {
            $minuty['chores'] = (float) DB::table('house_chore_log')
                ->where('gallery_space_id', $prostor->id)
                ->where('done_at', '>=', $od)
                ->sum('minutes');
        }

        if (Schema::hasTable('calendar_events')) {
            $udalosti = DB::table('calendar_events')
                ->where('gallery_space_id', $prostor->id)
                ->where('starts_at', '>=', $od)
                ->whereNotNull('ends_at')
                ->get(['starts_at', 'ends_at']);

            $minuty['calendar'] = (float) $udalosti->sum(
                fn (object $u) => CarbonImmutable::parse($u->starts_at)->diffInMinutes(CarbonImmutable::parse($u->ends_at)),
            );
        }

        if (Schema::hasTable('journal_entries')) {
            // Zápis v deníku i randíčko jsou stopa po čase, který spolu
            // strávili — kolik ho bylo, aplikace neví, tak počítá půl hodiny.
            $minuty['dates'] = 30.0 * DB::table('journal_entries')
                ->where('gallery_space_id', $prostor->id)
                ->where('created_at', '>=', $od)
                ->count();
        }

        if (Schema::hasTable('transactions')) {
            // Za každý pohyb pět minut: zapsat, zařadit, občas dohledat.
            $minuty['money'] = 5.0 * DB::table('transactions')
                ->where('gallery_space_id', $prostor->id)
                ->where('occurred_at', '>=', $od)
                ->count();
        }

        return array_filter($minuty, fn (float $m) => $m > 0);
    }

    /**
     * Co čeká na společné okno: `[{ name, need, route, tab, label }]`.
     *
     * @return list<array<string, mixed>>
     */
    private function cekaNaOkno(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('wellbeing_tasks')) {
            return [];
        }

        return DB::table('wellbeing_tasks')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('done_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $u) => [
                'id' => (int) $u->id,
                'name' => $u->name,
                'need' => (int) $u->needs_people,
                'route' => $u->route,
                'tab' => $u->tab,
                'label' => (string) ($u->label ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Otázka na dva: `[{ q, a, m, when }]`.
     *
     * Posílá se jen otázka, na kterou odpověděli **oba** — půlka rozhovoru
     * není rozhovor a druhý by si ji přečetl dřív, než odpoví sám.
     *
     * @return list<array<string, string>>
     */
    private function otazky(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('wellbeing_answers')) {
            return [];
        }

        $jmena = $this->jmena($prostor);
        $dvojice = array_keys($jmena);

        return DB::table('wellbeing_answers')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('asked_on')
            ->limit(60)
            ->get()
            ->groupBy(fn (object $o) => $o->asked_on.'|'.$o->question)
            ->filter(fn (Collection $o) => $o->pluck('user_id')->unique()->count() >= 2)
            ->map(function (Collection $odpovedi) use ($dvojice) {
                $podleLidi = $odpovedi->keyBy('user_id');
                $prvni = $odpovedi->first();

                return [
                    'q' => $prvni->question,
                    'a' => (string) ($podleLidi[$dvojice[0] ?? null]->answer ?? ''),
                    'm' => (string) ($podleLidi[$dvojice[1] ?? null]->answer ?? ''),
                    'when' => CarbonImmutable::parse($prvni->asked_on)->format('j. n.'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Dvojice jménem, vlastník první — obrazovka kreslí jeho vlevo.
     *
     * Prototyp klíčuje mapu **jménem**, ne identifikátorem. Dva členové se
     * shodným jménem by se tím do sebe složili a jeden by druhého přepsal;
     * druhý proto dostane číslo, stejně jako dvě Kláry v „Lidech".
     *
     * Řadí se tady, ne v SQL: `orderByRaw` přes `belongsToMany` pořadí
     * nedrží a vlastník pak skončil uprostřed.
     *
     * @return array<int, string>
     */
    public function jmena(GallerySpace $prostor): array
    {
        $lide = $prostor->members()
            ->get(['users.id', 'users.name'])
            ->sortBy(fn (object $u) => [(int) $u->id === (int) $prostor->owner_id ? 0 : 1, (int) $u->id])
            ->values();

        $jmena = [];
        $videno = [];

        foreach ($lide as $u) {
            $jmeno = (string) $u->name;
            $poradove = 2;

            while (in_array($jmeno, $videno, true)) {
                $jmeno = $u->name.' ('.$poradove++.')';
            }

            $videno[] = $jmeno;
            $jmena[(int) $u->id] = $jmeno;
        }

        return $jmena;
    }
}
