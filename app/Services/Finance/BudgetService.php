<?php

namespace App\Services\Finance;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetEntry;
use App\Models\GallerySpace;
use App\Models\MoneyRequest;
use App\Models\User;
use App\Notifications\GalleryNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Přehledy nad rozpočtem na období.
 *
 * Dvě rozhodnutí, která tvarují všechno ostatní:
 *
 * Měny se nesčítají napříč — ne aspoň tam, kde na číslech záleží. Plán, denní příděl
 * i varování stojí na hlavní měně rozpočtu a součty se drží po měnách zvlášť, protože
 * jedině tak sedí na haléř; kdo si podle přepočteného čísla naplánuje nájem, zjistí
 * rozdíl až na účtu.
 *
 * Vedle toho se posílá i součet přes měny, kurzem ECB a s datem. Je to výslovně odhad
 * a nikdy nenahrazuje součty po měnách — existuje proto, že „kolik jsme dohromady
 * utratili" je u páru, kde jeden platí v eurech a druhý v korunách, otázka číslo jedna
 * a bez přepočtu se na ni odpovědět nedá.
 *
 * Zbytek se počítá ke dnešku, ne k celému období. Odpověď na „kolik ještě můžu utratit"
 * je za půl roku v cizině užitečná jen tehdy, když bere v úvahu, kolik dní zbývá.
 */
class BudgetService
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    /** Rozpočty, na které tenhle člověk vidí. */
    public function forUser(GallerySpace $space, User $user): Collection
    {
        return Budget::where('gallery_space_id', $space->id)
            ->with('owner:id,name')
            ->orderByDesc('starts_on')
            ->get()
            ->filter(fn (Budget $budget) => $budget->isVisibleTo($user))
            ->values();
    }

    /**
     * Celý přehled jednoho rozpočtu.
     *
     * @return array<string, mixed>
     */
    public function overview(Budget $budget, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $budget->loadMissing([
            'categories', 'entries.category', 'entries.author:id,name',
            'settlements.from:id,name', 'settlements.to:id,name',
        ]);

        $vydaje = $budget->entries->where('kind', 'expense');
        $prijmy = $budget->entries->where('kind', 'income');

        return [
            'budget' => [
                'uuid' => $budget->uuid,
                'name' => $budget->name,
                'currency' => $budget->currency,
                'starts_on' => $budget->starts_on->toDateString(),
                'ends_on' => $budget->ends_on?->toDateString(),
                'monthly_income' => $budget->monthly_income !== null ? (float) $budget->monthly_income : null,
                'savings_target' => $budget->savings_target !== null ? (float) $budget->savings_target : null,
                'savings_target_on' => $budget->savings_target_on?->toDateString(),
                'period_unit' => $budget->period_unit ?? 'month',
                'period_label' => $budget->periodLabel(),
                'note' => $budget->note,
                'is_shared' => $budget->is_shared,
                'owner' => $budget->owner ? ['id' => $budget->owner->id, 'name' => $budget->owner->name] : null,
            ],
            'period' => $this->period($budget, $today),
            'totals' => [
                'spent' => $this->byCurrency($vydaje),
                'income' => $this->byCurrency($prijmy),
                // Součet přes měny. Druhá, výslovně označená informace — po měnách zvlášť
                // zůstává tím hlavním. Odpovídá na otázku, na kterou se jinak odpovědět
                // nedá: kolik jsme dohromady utratili, když jeden platí v eurech a druhý
                // v korunách. Null, když kurz chybí nebo je všechno v jedné měně.
                'spent_combined' => $this->rates->combine($this->byCurrency($vydaje), $budget->currency),
            ],
            'categories' => $this->categories($budget, $today),
            'months' => $this->months($budget),
            'allowance' => $this->allowance($budget, $vydaje, $today),
            // Co dochází. Prázdné pole je dobrá zpráva a nic se nekreslí.
            'warnings' => $this->warnings($budget, $today),
            'settlement' => $vyrovnani = $this->settlement($budget),
            // Dluh napříč měnami jedním číslem — jeden převod se posílá jednou částkou.
            'settlement_combined' => $this->settlementCombined($budget, $vyrovnani),
            'runway' => $this->runway($budget, $today),
            'savings' => $this->savings($budget, $today),
            'comparison' => $this->comparison($budget),
            // Co se každý měsíc samo připisuje. Prázdné je běžný stav a nic se nekreslí.
            'recurring' => $this->recurring($budget),
            // Co teprve přijde. Zbytek přehledu se dívá dozadu; tohle dopředu.
            'outlook' => $this->outlook($budget, $today),
            // Průběh čerpání den po dni — jsem teď napřed, nebo pozadu.
            'burndown' => $this->burndown($budget, $today),
            // Kdo kolik zaplatil. Jiná otázka než „kdo komu dluží".
            'by_payer' => $this->byPayer($budget),
            // Účastníci i s příjmem a podílem. Podíl je null, dokud nejsou vyplněné
            // aspoň dva příjmy ve stejné měně — pak se dělí napůl.
            'members' => $this->members($budget),
            // Kdo utrácí za co. Null u jednoho člověka, kde by to byl opsaný rozpad.
            'category_by_payer' => $this->categoryByPayer($budget),
            // Vývoj salda po měsících — jestli se rozdíl zvětšuje, nebo srovnává.
            'balance_trend' => $this->balanceTrend($budget),
            // Předpověď po kategoriích na příští měsíc, včetně toho, jak je spolehlivá.
            'prediction' => $this->prediction($budget, $today),
            // Historie uzávěrek. Bez ní by po vyrovnání zmizel dluh i důkaz, že se platil.
            'settlements' => $budget->settlements->take(6)->map(fn (\App\Models\BudgetSettlement $s) => [
                'uuid' => $s->uuid,
                'currency' => $s->currency,
                'amount' => (float) $s->amount,
                'settled_through' => $s->settled_through->toDateString(),
                'from' => $s->from?->name,
                'to' => $s->to?->name,
            ])->values()->all(),
            // Seznam položek sem nepatří. Dřív se posílalo sto nejnovějších — po nahrání
            // výpisu o pěti stech řádcích jich zbytek nešlo ani vidět, ani smazat.
            // Seznam si teď řídí vlastní koncový bod s hledáním a stránkováním; tady
            // zůstává jen počet, aby obrazovka věděla, kolik toho vlastně je.
            'entries_total' => $budget->entries->count(),
        ];
    }

    /** Kde v období jsme — kolik uplynulo a kolik zbývá. */
    private function period(Budget $budget, Carbon $today): array
    {
        $zacatek = $budget->starts_on;
        $konec = $budget->ends_on;

        $uplynulo = max(0, (int) $zacatek->diffInDays($today->min($konec ?? $today), false));
        $zbyva = $konec ? max(0, (int) $today->diffInDays($konec, false)) : null;

        return [
            'days_elapsed' => $uplynulo,
            'days_left' => $zbyva,
            'days_total' => $konec ? max(1, (int) $zacatek->diffInDays($konec)) : null,
            'has_started' => $today->greaterThanOrEqualTo($zacatek),
            'has_ended' => $konec !== null && $today->greaterThan($konec),
        ];
    }

    /**
     * Plán proti skutečnosti po kategoriích.
     *
     * Plán je měsíční, skutečnost za celé dosavadní období — porovnávat je proto má smysl
     * jen přes počet uplynulých měsíců, a ten se tady dopočítá, aby to nemusel dělat
     * frontend a spočítat to jinak.
     */
    private function categories(Budget $budget, Carbon $today): array
    {
        $konecObdobi = $budget->ends_on && $today->greaterThan($budget->ends_on) ? $budget->ends_on : $today;

        // Spodní hranice je pětina období, ne celé období. S jedničkou by čtvrtý den
        // z týdne tvrdil, že se smělo utratit všech sedm tisíc, a plán by nic neřídil.
        // Nula by zase v první den dělila skoro nulou a všechno by svítilo červeně;
        // pětina je kompromis — stejný, jaký používají varování.
        $mesicu = max(0.2, $budget->starts_on->diffInDays($konecObdobi) / $budget->periodDays());

        // Kategorie s přenosem počítá celé započaté měsíce, ne poměrnou část. Obálka na
        // jízdenku domů se naplní prvního; tvrdit patnáctého, že je v ní půlka měsíčního
        // vkladu, by u nepravidelného výdaje neodpovídalo tomu, jak se s ní zachází.
        $mesicuCelych = max(1.0, ceil($mesicu));

        return $budget->categories->map(function ($category) use ($budget, $mesicu, $mesicuCelych) {
            $utraceno = $budget->entries
                ->where('kind', 'expense')
                ->where('budget_category_id', $category->id)
                ->where('currency', $budget->currency)
                ->sum('amount');

            $prenos = (bool) ($category->rollover ?? false);
            $planovano = (float) $category->planned_monthly * ($prenos ? $mesicuCelych : $mesicu);

            return [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color,
                'icon' => $category->icon,
                'planned_monthly' => (float) $category->planned_monthly,
                'rollover' => $prenos,
                'planned_to_date' => round($planovano, 2),
                'spent' => round((float) $utraceno, 2),
                // Zbývající částka, ne jen procenta. Podle procent se nikdo nerozhoduje —
                // „112 %" neřekne, jestli je to o pět eur, nebo o pět set. Záporné číslo
                // znamená překročeno a je to na něm vidět bez počítání.
                'left' => round($planovano - (float) $utraceno, 2),
                // Nad sto procent je varování, ne chyba — někdo prostě utratil víc.
                'used_percent' => $planovano > 0 ? (int) round($utraceno / $planovano * 100) : null,
            ];
        })->values()->all();
    }

    /**
     * Měsíc po měsíci, aby šlo vidět, jestli to někam ujíždí.
     *
     * Jen hlavní měna. Bez toho filtru se pět tisíc korun přičetlo k eurům a graf
     * ukazoval měsíc jako dvakrát dražší, než jaký byl — přesně ta chyba, kvůli které
     * se nikde jinde v rozpočtu měny nesčítají.
     */
    private function months(Budget $budget): array
    {
        $mena = $budget->currency;

        return $budget->entries
            ->where('currency', $mena)
            ->groupBy(fn (BudgetEntry $entry) => $entry->spent_on->format('Y-m'))
            ->map(fn (Collection $mesic, string $klic) => [
                'month' => $klic,
                'spent' => round((float) $mesic->where('kind', 'expense')->sum('amount'), 2),
                'income' => round((float) $mesic->where('kind', 'income')->sum('amount'), 2),
                'count' => $mesic->count(),
                // Kolik položek měsíce se do grafu nevešlo, protože jsou v jiné měně.
                // Graf, který mlčky něco vynechá, je horší než graf, který to přizná.
                'other_currency_count' => $budget->entries
                    ->filter(fn (BudgetEntry $e) => $e->currency !== $mena && $e->spent_on->format('Y-m') === $klic)
                    ->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Kolik zbývá na den.
     *
     * Jediné číslo, které v cizině opravdu řídí chování. Počítá se z toho, co ještě
     * zbývá, a z počtu zbývajících dní — ne z původního plánu, který už neplatí.
     */
    private function allowance(Budget $budget, Collection $vydaje, Carbon $today): array
    {
        $rozpocet = (float) $budget->categories->sum('planned_monthly') * $budget->monthsCovered();
        $utraceno = (float) $vydaje->where('currency', $budget->currency)->sum('amount');
        $zbyva = round($rozpocet - $utraceno, 2);

        $dniDoKonce = $budget->ends_on ? max(1, (int) $today->diffInDays($budget->ends_on, false)) : null;

        return [
            'planned_total' => round($rozpocet, 2),
            'spent' => round($utraceno, 2),
            'left' => $zbyva,
            'per_day' => $dniDoKonce ? round($zbyva / $dniDoKonce, 2) : null,
            'days_left' => $dniDoKonce,
            'currency' => $budget->currency,
        ];
    }

    /**
     * Kategorie, které docházejí nebo už přetekly.
     *
     * Počítá se proti tomu, co mělo být utraceno k dnešku, ne proti celému období —
     * utratit polovinu rozpočtu na jídlo je v půlce měsíce v pořádku a po týdnu ne, a
     * číslo, které tenhle rozdíl nevidí, varuje buď pořád, nebo nikdy.
     *
     * @return array<int, array<string, mixed>>
     */
    public function warnings(Budget $budget, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $budget->loadMissing(['categories', 'entries']);

        $konec = $budget->ends_on && $today->greaterThan($budget->ends_on) ? $budget->ends_on : $today;
        $mesicu = max(0.2, $budget->starts_on->diffInDays($konec) / $budget->periodDays());

        $varovani = [];

        foreach ($budget->categories as $category) {
            if ((float) $category->planned_monthly <= 0) continue;

            $utraceno = (float) $budget->entries
                ->where('kind', 'expense')
                ->where('budget_category_id', $category->id)
                ->where('currency', $budget->currency)
                ->sum('amount');

            $melo = (float) $category->planned_monthly * $mesicu;
            if ($melo <= 0) continue;

            $podil = $utraceno / $melo;
            if ($podil < 0.8) continue;

            $varovani[] = [
                'category' => $category->name,
                'spent' => round($utraceno, 2),
                'planned_to_date' => round($melo, 2),
                'percent' => (int) round($podil * 100),
                'level' => $podil >= 1 ? 'over' : 'close',
            ];
        }

        return $varovani;
    }

    /**
     * Kdo komu kolik dluží.
     *
     * Nepočítají se převody tam a zpět, ale jediné číslo na konci: kdo zaplatil za
     * druhého víc, než druhý za něj. Deset drobných vyrovnání nikdo nedělá, jedno na
     * konci měsíce ano — a o to jde, aby se to vůbec vyrovnalo.
     *
     * Po měnách zvlášť, ze stejného důvodu jako všude jinde: dluh má sedět na haléř.
     * Přepočet na jedno číslo dělá settlementCombined() a je označený jako odhad.
     *
     * Co je jednou vyrovnané, se už nepočítá. Bez toho by číslo po zaplacení svítilo dál
     * a příští měsíc se k němu přičetlo nové — panel by po půl roce ukazoval součet
     * všeho, co kdy kdo za koho zaplatil, místo toho, co ještě zbývá srovnat.
     *
     * @return array<int, array<string, mixed>>
     */
    public function settlement(Budget $budget): array
    {
        $budget->loadMissing(['entries.payer:id,name', 'entries.author:id,name', 'settlements']);

        // Do kdy je která měna vyrovnaná. Bere se poslední uzávěrka.
        $vyrovnanoDo = $budget->settlements
            ->groupBy('currency')
            ->map(fn (Collection $skupina) => $skupina->max('settled_through'));

        // Podíly podle příjmu, když jsou vyplněné. Null znamená „dělí se napůl".
        $podily = $budget->incomeShares();

        // Kdo za koho utratil. Klíč je měna, pak plátce.
        $saldo = [];

        foreach ($budget->entries->where('kind', 'expense') as $entry) {
            if ($entry->split === 'none') continue;

            $mez = $vyrovnanoDo[$entry->currency] ?? null;
            if ($mez !== null && $entry->spent_on->lessThanOrEqualTo($mez)) continue;

            $platce = $entry->paid_by ?? $entry->user_id;
            if (! $platce) continue;

            $dluh = match ($entry->split) {
                // Za druhého: dluží celou částku.
                'other' => (float) $entry->amount,
                /*
                 * Podle poměru příjmů. Druhý dluží svůj podíl — kdo vydělává dvakrát
                 * tolik, nese dvě třetiny. Když poměr neznáme (chybí příjem, je vyplněný
                 * jen u jednoho, nebo jsou v různých měnách), spadne to na půlku:
                 * odhadovaný poměr by u peněz byl horší než přiznaná polovina.
                 */
                'ratio' => $podily !== null && isset($podily[$platce])
                    ? (float) $entry->amount * (1 - $podily[$platce])
                    : (float) $entry->amount / 2,
                // Napůl.
                default => (float) $entry->amount / 2,
            };

            $saldo[$entry->currency][$platce] = ($saldo[$entry->currency][$platce] ?? 0) + $dluh;
        }

        // Jména podle id. Ne přes flatMap: ten uvnitř dělá array_merge, a ten číselné
        // klíče přečísluje — z mapy id→jméno by vznikl obyčejný seznam a k saldu by se
        // pak přiřadilo cizí jméno.
        $jmena = [];

        foreach ($budget->entries as $e) {
            if ($e->paid_by && $e->payer) {
                $jmena[$e->paid_by] = $e->payer->name;
            }
            if ($e->user_id && $e->author) {
                $jmena[$e->user_id] = $e->author->name;
            }
        }

        $vysledek = [];

        foreach ($saldo as $mena => $podleOsoby) {
            // U dvou lidí je výsledek přesný: rozdíl toho, co jeden dal za druhého, a naopak.
            // U tří a víc se ukáže jen největší dvojice — zbytek by chtěl skutečné
            // vyrovnání grafu dluhů a tenhle systém je pro dva.
            $osoby = array_keys($podleOsoby);
            if (count($osoby) === 1) {
                // Platil jen jeden. Dluží mu ten druhý — a když je prostor dvoučlenný,
                // dá se pojmenovat. „Někdo ti dluží" je informace, se kterou se nedá nic
                // dělat; „Makinka ti dluží" ano.
                $druhy = $this->protistrana($budget, (int) $osoby[0]);

                $vysledek[] = [
                    'currency' => $mena,
                    'from' => $druhy?->name,
                    'from_id' => $druhy?->id,
                    'to' => $jmena[$osoby[0]] ?? '—',
                    'to_id' => $osoby[0],
                    'amount' => round($podleOsoby[$osoby[0]], 2),
                ];

                continue;
            }

            arsort($podleOsoby);
            $nejvic = array_key_first($podleOsoby);
            $nejmin = array_key_last($podleOsoby);
            $rozdil = round($podleOsoby[$nejvic] - $podleOsoby[$nejmin], 2);

            if ($rozdil < 0.01) continue;

            $vysledek[] = [
                'currency' => $mena,
                'from' => $jmena[$nejmin] ?? '—',
                'from_id' => $nejmin,
                'to' => $jmena[$nejvic] ?? '—',
                'to_id' => $nejvic,
                'amount' => $rozdil,
            ];
        }

        // Od kdy se v každé měně počítá — aby panel mohl napsat „od 14. srpna" a bylo
        // jasné, že se nedívám na součet za celé období.
        foreach ($vysledek as $i => $radek) {
            $vysledek[$i]['since'] = ($vyrovnanoDo[$radek['currency']] ?? null)?->copy()->addDay()->toDateString();
        }

        return $vysledek;
    }

    /**
     * Dluh napříč měnami jedním číslem.
     *
     * „Dlužíš mi osm set eur a dva a půl tisíce korun" je věta, po které si oba sednou
     * k počítání. Jeden převod se posílá jednou částkou, takže tohle číslo je přesně to,
     * co člověk potřebuje — s kurzem a jeho datem, aby bylo vidět, odkud se vzalo.
     *
     * Počítá se jen tehdy, když všechny řádky míří stejným směrem. Když jeden dluží
     * v korunách a druhý v eurech, jediný převod z toho neudělá nic a součet by lhal.
     *
     * @param  array<int, array<string, mixed>>  $vyrovnani
     * @return array<string, mixed>|null
     */
    public function settlementCombined(Budget $budget, array $vyrovnani): ?array
    {
        if (count($vyrovnani) < 2) return null;

        $smery = collect($vyrovnani)->map(fn (array $r) => ($r['from_id'] ?? null).'→'.($r['to_id'] ?? null))->unique();

        if ($smery->count() !== 1) return null;

        $podleMeny = collect($vyrovnani)->mapWithKeys(fn (array $r) => [$r['currency'] => (float) $r['amount']])->all();

        $soucet = $this->rates->combine($podleMeny, $budget->currency);

        return $soucet === null ? null : $soucet + [
            'from' => $vyrovnani[0]['from'] ?? null,
            'to' => $vyrovnani[0]['to'] ?? null,
        ];
    }

    /**
     * Uzavře dluh v jedné měně ke dni.
     *
     * Nemaže ani neoznačuje položky. Jedno datum říká totéž jako dvě stě příznaků, dá se
     * vzít zpět a zůstane po něm historie.
     */
    public function settleUp(Budget $budget, User $user, string $mena, ?Carbon $doDne = null): \App\Models\BudgetSettlement
    {
        $doDne ??= Carbon::today();

        // Částka se bere z aktuálního výpočtu, ne od klienta — jinak by šlo uzavřít dluh
        // libovolným číslem a historie by lhala.
        $radek = collect($this->settlement($budget))->firstWhere('currency', strtoupper($mena));

        $zaznam = \App\Models\BudgetSettlement::create([
            'budget_id' => $budget->id,
            'currency' => strtoupper($mena),
            'settled_through' => $doDne->toDateString(),
            'amount' => $radek['amount'] ?? 0,
            'from_user_id' => $radek['from_id'] ?? null,
            'to_user_id' => $radek['to_id'] ?? null,
            'created_by' => $user->id,
        ]);

        $budget->unsetRelation('settlements');

        return $zaznam;
    }

    /**
     * Kdy při současném tempu dojdou peníze.
     *
     * Tempo se počítá z posledních třiceti dnů, ne z celého období: kdo první měsíc
     * platil kauci a vybavení, má průměr od začátku nesmyslně vysoký a předpověď by
     * strašila zbytečně.
     *
     * @return array<string, mixed>|null
     */
    public function runway(Budget $budget, ?Carbon $today = null): ?array
    {
        $today ??= Carbon::today();
        $budget->loadMissing(['categories', 'entries']);

        // Okno je třicet dnů, ale nesahá před začátek rozpočtu: u pětidenní cesty by se
        // pět dnů útraty dělilo třiceti a tempo by vyšlo šestkrát nižší, než jaké je.
        $od = $today->copy()->subDays(30)->max($budget->starts_on);

        $nedavne = $budget->entries
            ->where('kind', 'expense')
            ->where('currency', $budget->currency)
            ->filter(fn (BudgetEntry $e) => $e->spent_on->greaterThanOrEqualTo($od));

        // Míň než tři položky za měsíc není tempo, ze kterého se dá cokoli odvodit.
        if ($nedavne->count() < 3) return null;

        $dni = max(1, (int) $od->diffInDays($today));
        $naDen = (float) $nedavne->sum('amount') / $dni;

        if ($naDen <= 0) return null;

        $rozpocet = (float) $budget->categories->sum('planned_monthly') * $budget->monthsCovered();
        $utraceno = (float) $budget->entries->where('kind', 'expense')->where('currency', $budget->currency)->sum('amount');
        $zbyva = $rozpocet - $utraceno;

        if ($zbyva <= 0) {
            return ['per_day' => round($naDen, 2), 'days_left' => 0, 'runs_out_on' => $today->toDateString(), 'covers_period' => false];
        }

        $dniDoNuly = (int) floor($zbyva / $naDen);
        $dojde = $today->copy()->addDays($dniDoNuly);

        return [
            'per_day' => round($naDen, 2),
            'days_left' => $dniDoNuly,
            'runs_out_on' => $dojde->toDateString(),
            // Jestli to vydrží do konce období — jediná otázka, na které opravdu záleží.
            'covers_period' => $budget->ends_on === null || $dojde->greaterThanOrEqualTo($budget->ends_on),
        ];
    }

    /**
     * Kolik měsíčně odkládat, aby cíl do data vyšel.
     *
     * Počítá se z toho, co ještě chybí, a ze zbývajících měsíců — ne z původního plánu,
     * který po prvním vynechaném měsíci přestal platit.
     */
    public function savings(Budget $budget, ?Carbon $today = null): ?array
    {
        if ($budget->savings_target === null) return null;

        $today ??= Carbon::today();
        $budget->loadMissing('entries');

        // Odloženo = příjmy minus výdaje v hlavní měně. Hrubé, ale poctivé: co zbylo.
        $prijem = (float) $budget->entries->where('kind', 'income')->where('currency', $budget->currency)->sum('amount');
        $vydaj = (float) $budget->entries->where('kind', 'expense')->where('currency', $budget->currency)->sum('amount');
        $odlozeno = max(0, $prijem - $vydaj);

        $cil = (float) $budget->savings_target;
        $chybi = max(0, $cil - $odlozeno);

        // Se znaménkem: bez něj by cíl, jehož datum už uplynulo, vyšel jako by do něj
        // zbývalo stejně dní, kolik jich uteklo, a aplikace by klidně počítala měsíční
        // splátku na termín, který je dávno pryč.
        $doData = $budget->savings_target_on;
        $dniDoCile = $doData ? (int) $today->diffInDays($doData, false) : null;
        $mesicu = $dniDoCile !== null && $dniDoCile > 0 ? $dniDoCile / 30.44 : null;

        return [
            'target' => round($cil, 2),
            'saved' => round($odlozeno, 2),
            'missing' => round($chybi, 2),
            'percent' => $cil > 0 ? min(100, (int) round($odlozeno / $cil * 100)) : 0,
            'target_on' => $doData?->toDateString(),
            'days_left' => $dniDoCile,
            'monthly_needed' => $mesicu ? round($chybi / $mesicu, 2) : null,
            // Termín uplynul a cíl není naplněn — to není „kolik měsíčně", ale konstatování.
            'overdue' => $dniDoCile !== null && $dniDoCile < 0 && $chybi > 0,
            'reached' => $chybi <= 0,
        ];
    }

    /** Ten druhý v prostoru. Null, když jich je víc než dva — pak není „ten druhý". */
    private function protistrana(Budget $budget, int $krome): ?User
    {
        $clenove = $budget->gallerySpace?->members()->get(['users.id', 'users.name']) ?? collect();

        return $clenove->count() === 2
            ? $clenove->firstWhere('id', '!=', $krome)
            : null;
    }

    /**
     * Co se každý měsíc opakuje.
     *
     * Označit položku za pravidelnou šlo, ale zjistit, co všechno se kvůli tomu každý
     * měsíc samo připisuje, ne. Nájem po odstěhování běžel dál a člověk to poznal až
     * podle toho, že mu nesedí zbytek.
     *
     * Skládá se stejným klíčem, jakým je hledá příkaz, který kopie vytváří — kategorie,
     * popis a druh. Kdyby se seskupovalo jinak, seznam by ukazoval něco jiného, než co
     * se doopravdy děje.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recurring(Budget $budget): array
    {
        $budget->loadMissing(['entries.category', 'entries.payer:id,name', 'entries.author:id,name']);

        return $budget->entries
            ->where('is_recurring', true)
            ->sortByDesc('spent_on')
            ->groupBy(fn (BudgetEntry $e) => $e->budget_category_id.'|'.$e->note.'|'.$e->kind)
            ->map(function (Collection $skupina) {
                $posledni = $skupina->first();

                return [
                    // uuid poslední položky: podle ní se opakování zastavuje, protože
                    // právě z ní příkaz vyrábí kopii pro další měsíc.
                    'uuid' => $posledni->uuid,
                    'kind' => $posledni->kind,
                    'amount' => (float) $posledni->amount,
                    'currency' => $posledni->currency,
                    'note' => $posledni->note,
                    'category' => $posledni->category?->name,
                    'day_of_month' => (int) $posledni->spent_on->day,
                    'split' => $posledni->split,
                    'paid_by' => $posledni->payer?->name ?? $posledni->author?->name,
                    'last_on' => $posledni->spent_on->toDateString(),
                    // Kolikrát už proběhlo — z toho je poznat, jestli jde o zavedenou
                    // pravidelnou platbu, nebo o něco, co se zaškrtlo omylem.
                    'occurrences' => $skupina->count(),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Co teprve přijde a jak to dopadne.
     *
     * Celý zbytek přehledu se dívá dozadu — kolik se utratilo, kolik zbylo, jak vyšel
     * minulý měsíc. To odpoví na otázku „jak jsem na tom", ale ne na tu, kterou si člověk
     * v cizině klade doopravdy: vyjde mi to do konce? Zbývající částka dělená počtem dní
     * tvrdí, že ano, i když třetího přijde nájem, který ji spolkne celou.
     *
     * Předpověď stojí na dvou různých věcech a drží je oddělené, protože každá je jinak
     * jistá. Pravidelné platby jsou skoro jisté: víme, co to je, kolik to je a kolikátého
     * to chodí. Zbytek je odhad z dosavadního tempa nepravidelných výdajů — ten může být
     * vedle, a proto se ukazuje zvlášť a je z něj vidět, že je to odhad.
     *
     * Počítá se jen v měně rozpočtu. Sečíst do předpovědi částky v jiné měně by znamenalo
     * hádat kurz na měsíce dopředu, což je horší než ho neznat — položky v cizí měně se
     * proto v seznamu „co přijde" objeví, ale do součtu nevstupují.
     *
     * @return array<string, mixed>|null
     */
    public function outlook(Budget $budget, ?Carbon $today = null): ?array
    {
        $today ??= Carbon::today();

        if ($budget->ends_on === null || $today->greaterThan($budget->ends_on)) {
            // Bez konce období není co předpovídat — „do kdy" chybí zadání.
            return null;
        }

        $rozvaha = $this->allowance($budget, $budget->entries->where('kind', 'expense'), $today);

        if ($rozvaha['planned_total'] <= 0) {
            // Bez plánu nemá věta „zbude z plánu" o čem mluvit. Čerstvý rozpočet bez
            // kategorií by jinak hlásil „do konce období to vychází" s nulou — což je
            // formálně pravda a prakticky lež, protože se nic neporovnávalo.
            return null;
        }

        $pravidelne = $this->recurring($budget);
        $konec = $budget->ends_on->copy();

        $nadchazejici = [];
        $doKonceVydaje = 0.0;
        $doKoncePrijmy = 0.0;

        foreach ($pravidelne as $polozka) {
            foreach ($this->dalsiTerminy($polozka['day_of_month'], $today, $konec) as $termin) {
                $vlastniMena = $polozka['currency'] === $budget->currency;

                if ($vlastniMena) {
                    if ($polozka['kind'] === 'income') {
                        $doKoncePrijmy += $polozka['amount'];
                    } else {
                        $doKonceVydaje += $polozka['amount'];
                    }
                }

                $zaKolikDni = (int) $today->diffInDays($termin, false);

                if ($zaKolikDni <= self::VYHLED_DNI) {
                    $nadchazejici[] = [
                        'note' => $polozka['note'],
                        'category' => $polozka['category'],
                        'kind' => $polozka['kind'],
                        'amount' => $polozka['amount'],
                        'currency' => $polozka['currency'],
                        'due_on' => $termin->toDateString(),
                        'days_away' => $zaKolikDni,
                        'in_budget_currency' => $vlastniMena,
                    ];
                }
            }
        }

        usort($nadchazejici, fn (array $a, array $b) => $a['days_away'] <=> $b['days_away']);

        $dniDoKonce = max(1, (int) $today->diffInDays($konec, false));
        $odhadNepravidelnych = $this->tempoNepravidelnych($budget, $today) * $dniDoKonce;

        $zbyva = $rozvaha['left'];

        // Příjem se do předpovědi nepřičítá, i když ho známe. `left` není zůstatek na
        // účtu, ale kolik zbývá z plánu — a plán se tím, že přijde výplata, nezvětší.
        // Připočíst ji by znamenalo, že by rozpočet vypadal tím lépe, čím víc se vydělá,
        // i kdyby se přitom utrácelo nad plán. Kolik ještě přijde, se hlásí zvlášť.
        $zbudeNaKonci = round($zbyva - $doKonceVydaje - $odhadNepravidelnych, 2);

        return [
            'currency' => $budget->currency,
            'horizon_days' => self::VYHLED_DNI,
            'upcoming' => $nadchazejici,
            'ends_on' => $konec->toDateString(),
            'days_left' => $dniDoKonce,
            'recurring_expense' => round($doKonceVydaje, 2),
            'recurring_income' => round($doKoncePrijmy, 2),
            'variable_estimate' => round($odhadNepravidelnych, 2),
            'projected_left' => $zbudeNaKonci,
            // Tři stavy, ne dva: těsně znamená „vyjde to, ale bez rezervy", a to je jiná
            // informace než „vyjde to" i než „nevyjde".
            //
            // Rezerva se poměřuje s velikostí plánu, ne s pravidelnými platbami. Rozpočet,
            // ve kterém žádná pravidelná platba není, by jinak hlásil „v pořádku" i s pěti
            // eury do konce, protože nula krát cokoliv je nula.
            'verdict' => $zbudeNaKonci < 0
                ? 'short'
                : ($zbudeNaKonci < $rozvaha['planned_total'] * self::TESNA_REZERVA ? 'tight' : 'ok'),
        ];
    }

    /**
     * Předpověď výdajů po kategoriích na příští měsíc.
     *
     * Odhad, který výhled používá pro celý rozpočet, je jedno číslo: dosavadní tempo krát
     * zbývající dny. To stačí na otázku „vyjde to", ale ne na „kde to praskne" — a právě
     * to je informace, se kterou se dá něco udělat ještě předtím, než praskne.
     *
     * Počítá se po kategoriích a ze tří věcí zvlášť, protože každá se chová jinak:
     *
     *   — Pravidelné platby se neodhadují. Nájem je devět set padesát, ne „přibližně
     *     devět set padesát podle historie", a průměrovat ho by znamenalo zanést chybu
     *     do jediného čísla, které je jisté.
     *   — U nepravidelných se bere průměr posledních měsíců, ale s větší vahou na ty
     *     bližší. Kdo v květnu utrácel jinak než v srpnu, má srpen blíž pravdě.
     *   — Trend se hlásí zvlášť, ne zapracovaný do čísla. „Roste to" a „bude to tolik"
     *     jsou dvě různá tvrzení a slepit je znamená tvrdit víc, než data unesou.
     *
     * Spolehlivost se přiznává. Z jednoho měsíce se předpovídat nedá a předstírat, že
     * ano, je u peněz horší než mlčet — proto se u každé kategorie posílá, z kolika
     * měsíců odhad vznikl, a volající to má ukázat.
     *
     * @return array<string, mixed>|null
     */
    public function prediction(Budget $budget, ?Carbon $today = null): ?array
    {
        $today ??= Carbon::today();
        $budget->loadMissing(['categories', 'entries']);

        $vydaje = $budget->entries
            ->where('kind', 'expense')
            ->where('currency', $budget->currency);

        if ($vydaje->isEmpty()) {
            return null;
        }

        // Jen dokončené měsíce. Rozestavěný měsíc by táhl odhad dolů tím, že v něm
        // ještě nestihlo přijít to, co obvykle chodí ke konci.
        $zacatekTohoto = $today->copy()->startOfMonth();

        $radky = $budget->categories->map(function (BudgetCategory $kategorie) use ($vydaje, $zacatekTohoto) {
            $moje = $vydaje->where('budget_category_id', $kategorie->id);

            $pravidelne = (float) $moje->where('is_recurring', true)
                ->groupBy(fn (BudgetEntry $e) => $e->spent_on->format('Y-m'))
                ->map(fn (Collection $m) => $m->sum('amount'))
                ->avg() ?: 0.0;

            $poMesicich = $moje
                ->where('is_recurring', false)
                ->filter(fn (BudgetEntry $e) => $e->spent_on->lessThan($zacatekTohoto))
                ->groupBy(fn (BudgetEntry $e) => $e->spent_on->format('Y-m'))
                ->map(fn (Collection $m) => (float) $m->sum('amount'))
                ->sortKeys();

            return [
                'id' => $kategorie->id,
                'name' => $kategorie->name,
                'color' => $kategorie->color,
                'recurring' => round($pravidelne, 2),
                'variable' => round($this->vazenyPrumer($poMesicich->values()->all()), 2),
                'trend' => $this->trend($poMesicich->values()->all()),
                'months' => $poMesicich->count(),
                'planned_monthly' => (float) $kategorie->planned_monthly,
            ];
        })->map(fn (array $r) => $r + ['predicted' => round($r['recurring'] + $r['variable'], 2)])
            ->sortByDesc('predicted')
            ->values();

        $mesicu = $radky->max('months') ?? 0;

        return [
            'currency' => $budget->currency,
            'for_month' => $today->copy()->addMonthNoOverflow()->format('Y-m'),
            'months_measured' => $mesicu,
            // Pod třemi dokončenými měsíci je z toho spíš dojem než předpověď a obrazovka
            // to má říct rovnou, ne to schovat do poznámky pod čarou.
            'reliable' => $mesicu >= 3,
            'total' => round($radky->sum('predicted'), 2),
            'planned_total' => round($radky->sum('planned_monthly'), 2),
            'rows' => $radky->all(),
        ];
    }

    /**
     * Průměr s větší vahou na novější měsíce.
     *
     * Prostý průměr bere půlroční útratu stejně vážně jako minulý měsíc, a to je u výdajů
     * špatně — ceny se mění, zvyky taky. Váhy rostou lineárně (1, 2, 3…), což je hrubé,
     * ale u pěti šesti měsíců to stačí a hlavně je na tom vidět, co se počítá.
     *
     * @param  list<float>  $hodnoty  od nejstaršího po nejnovější
     */
    private function vazenyPrumer(array $hodnoty): float
    {
        if ($hodnoty === []) {
            return 0.0;
        }

        $soucet = 0.0;
        $vahy = 0.0;

        foreach (array_values($hodnoty) as $i => $hodnota) {
            $vaha = $i + 1;
            $soucet += $hodnota * $vaha;
            $vahy += $vaha;
        }

        return $vahy > 0 ? $soucet / $vahy : 0.0;
    }

    /**
     * Kam to jde — nahoru, dolů, nebo nikam.
     *
     * Porovnává se poslední měsíc s průměrem předchozích. Pásmo deseti procent je
     * schválně široké: u výdajů domácnosti se pár procent nahoru a dolů děje pořád
     * a hlásit to jako trend by znamenalo hlásit šum.
     *
     * @param  list<float>  $hodnoty
     */
    private function trend(array $hodnoty): string
    {
        if (count($hodnoty) < 3) {
            return 'unknown';
        }

        $posledni = (float) end($hodnoty);
        $predchozi = array_slice($hodnoty, 0, -1);
        $zaklad = array_sum($predchozi) / count($predchozi);

        if ($zaklad <= 0) {
            return 'unknown';
        }

        $zmena = ($posledni - $zaklad) / $zaklad;

        return $zmena > 0.1 ? 'up' : ($zmena < -0.1 ? 'down' : 'flat');
    }

    /**
     * Jak se saldo vyvíjí po měsících.
     *
     * Rozvaha řekne, kdo komu dluží dnes. Neřekne, jestli se to zhoršuje — a to je jiná
     * otázka. Dva tisíce, které se každý měsíc srovnávají, jsou něco jiného než dva tisíce,
     * které každý měsíc rostou o pět set, i když dnešní číslo je stejné.
     *
     * Kladné číslo znamená, že první z dvojice vydal víc za druhého; záporné naopak.
     * Bere se hrubé saldo z položek, ne po uzávěrkách — uzávěrka je zaplacení, ne důvod,
     * proč rozdíl vznikl, a graf má ukázat právě ten důvod.
     *
     * @return array<string, mixed>|null
     */
    public function balanceTrend(Budget $budget): ?array
    {
        $budget->loadMissing(['entries.payer:id,name', 'entries.author:id,name']);

        $delene = $budget->entries
            ->where('kind', 'expense')
            ->where('currency', $budget->currency)
            ->filter(fn (BudgetEntry $e) => $e->split !== 'none');

        if ($delene->isEmpty()) {
            return null;
        }

        $podily = $budget->incomeShares();
        $lide = [];

        foreach ($delene as $polozka) {
            $kdo = $polozka->payer ?? $polozka->author;

            if ($kdo) {
                $lide[$kdo->id] = $kdo->name;
            }
        }

        if (count($lide) !== 2) {
            // Trend má smysl mezi dvěma. U tří a víc by jedna čára slučovala nesouvisející
            // dvojice a její znaménko by neznamenalo nic.
            return null;
        }

        [$prvni, $druhy] = array_keys($lide);

        $mesice = $delene
            ->groupBy(fn (BudgetEntry $e) => $e->spent_on->format('Y-m'))
            ->sortKeys()
            ->map(function (Collection $skupina) use ($prvni, $podily) {
                $rozdil = 0.0;

                foreach ($skupina as $polozka) {
                    $platce = $polozka->paid_by ?? $polozka->user_id;

                    $dluh = match ($polozka->split) {
                        'other' => (float) $polozka->amount,
                        'ratio' => $podily !== null && isset($podily[$platce])
                            ? (float) $polozka->amount * (1 - $podily[$platce])
                            : (float) $polozka->amount / 2,
                        default => (float) $polozka->amount / 2,
                    };

                    // Znaménko podle toho, kdo platil: co vydal první, jde nahoru.
                    $rozdil += $platce === $prvni ? $dluh : -$dluh;
                }

                return round($rozdil, 2);
            });

        $narustajici = 0.0;
        $body = [];

        foreach ($mesice as $klic => $zaMesic) {
            $narustajici += $zaMesic;

            $body[] = [
                'month' => $klic,
                'change' => $zaMesic,
                // Kumulativní saldo je to zajímavé číslo: měsíční rozdíl kolísá,
                // ale jestli se dluh celkově srovnává, je vidět až na součtu.
                'balance' => round($narustajici, 2),
            ];
        }

        return [
            'currency' => $budget->currency,
            'first' => ['id' => $prvni, 'name' => $lide[$prvni]],
            'second' => ['id' => $druhy, 'name' => $lide[$druhy]],
            'points' => $body,
        ];
    }

    /**
     * Kategorie křížem s lidmi — kdo utrácí za co.
     *
     * Rozpad po kategoriích a rozpad po lidech jsou dvě osy téhož a každá zvlášť
     * odpovídá jen na půlku otázky. „Jídlo stojí 540" a „Adrian zaplatil 4700" nedají
     * dohromady „kdo z nás nakupuje jídlo" — a přitom právě tahle otázka vede k tomu,
     * že se výdaje přerozdělí jinak.
     *
     * Jen měna rozpočtu. Sečíst přes měny by znamenalo hádat kurz a tabulka, ve které je
     * jedna buňka v eurech a druhá v korunách, se nedá porovnávat po řádcích.
     *
     * @return array<string, mixed>|null
     */
    public function categoryByPayer(Budget $budget): ?array
    {
        $budget->loadMissing(['categories', 'entries.payer:id,name', 'entries.author:id,name']);

        $vydaje = $budget->entries
            ->where('kind', 'expense')
            ->where('currency', $budget->currency);

        if ($vydaje->isEmpty()) {
            return null;
        }

        $lide = [];
        $bunky = [];

        foreach ($vydaje as $polozka) {
            $kdo = $polozka->payer ?? $polozka->author;

            if (! $kdo) {
                continue;
            }

            $lide[$kdo->id] = $kdo->name;
            // Položka bez kategorie má klíč 0 — „nezařazeno" je taky odpověď a vynechat
            // ji by znamenalo, že součty v tabulce nesedí s celkem.
            $klic = $polozka->budget_category_id ?? 0;
            $bunky[$klic][$kdo->id] = ($bunky[$klic][$kdo->id] ?? 0) + (float) $polozka->amount;
        }

        if (count($lide) < 2) {
            // U jednoho člověka je křížení jen opsaný rozpad po kategoriích.
            return null;
        }

        $nazvy = $budget->categories->pluck('name', 'id')->all() + [0 => 'Bez kategorie'];

        $radky = collect($bunky)
            ->map(fn (array $podleLidi, int $klic) => [
                'category_id' => $klic ?: null,
                'name' => $nazvy[$klic] ?? 'Bez kategorie',
                'total' => round(array_sum($podleLidi), 2),
                'by_payer' => collect($podleLidi)->map(fn (float $c) => round($c, 2))->all(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'currency' => $budget->currency,
            'people' => collect($lide)->map(fn (string $jmeno, int $id) => ['id' => $id, 'name' => $jmeno])->values()->all(),
            'rows' => $radky,
        ];
    }

    /**
     * Účastníci rozpočtu i s příjmem a podílem na společných výdajích.
     *
     * Vypisují se všichni členové prostoru, ne jen ti s vyplněným příjmem — obrazovka
     * potřebuje vědět, koho se zeptat, a prázdné pole je informace („tenhle to neuvedl"),
     * kterou by seznam jen vyplněných zatajil.
     *
     * @return array<int, array<string, mixed>>
     */
    public function members(Budget $budget): array
    {
        $budget->loadMissing(['members.user:id,name', 'gallerySpace']);

        $podily = $budget->incomeShares();
        $zapsani = $budget->members->keyBy('user_id');

        $lide = $budget->gallerySpace?->members()->get(['users.id', 'users.name']) ?? collect();

        return $lide->map(function ($clovek) use ($zapsani, $podily, $budget) {
            $radek = $zapsani->get($clovek->id);

            return [
                'user_id' => (int) $clovek->id,
                'name' => $clovek->name,
                'monthly_income' => $radek?->monthly_income !== null ? (float) $radek->monthly_income : null,
                'currency' => $radek?->currency ?? $budget->currency,
                // Podíl na společných výdajích. Null znamená, že se poměr nedá spočítat
                // a dělí se napůl.
                'share' => $podily[$clovek->id] ?? null,
            ];
        })->values()->all();
    }

    /**
     * Kdo kolik zaplatil.
     *
     * Údaj o plátci se u každé položky sbírá a používá se k vyrovnání — kdo komu dluží.
     * To je ale jiná otázka než tahle. Vyrovnání počítá jen s tím, co se dělí; kdo víc
     * platil věci, které dělené nejsou, v něm není vidět vůbec.
     *
     * U dvou lidí na dálku je „kolikátý měsíc platím nájem já" informace, kvůli které
     * se vedou hovory, a dosud se dala zjistit jen listováním v položkách.
     *
     * Po měnách zvlášť, jako všude jinde. Kdo nemá zapsaného plátce, spadne pod autora
     * zápisu — u starých položek nemáme nic lepšího a tvářit se, že ano, by čísla
     * rozhodilo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byPayer(Budget $budget): array
    {
        $budget->loadMissing(['entries.payer:id,name', 'entries.author:id,name']);

        $vydaje = $budget->entries->where('kind', 'expense');
        $celkem = [];

        foreach ($vydaje as $polozka) {
            $kdo = $polozka->payer ?? $polozka->author;

            if (! $kdo) {
                continue;
            }

            $celkem[$kdo->id]['name'] = $kdo->name;
            $celkem[$kdo->id]['currencies'][$polozka->currency] =
                ($celkem[$kdo->id]['currencies'][$polozka->currency] ?? 0) + (float) $polozka->amount;
            $celkem[$kdo->id]['count'] = ($celkem[$kdo->id]['count'] ?? 0) + 1;
        }

        return collect($celkem)
            ->map(fn (array $radek, int $id) => [
                'id' => $id,
                'name' => $radek['name'],
                'count' => $radek['count'],
                'currencies' => collect($radek['currencies'])->map(fn (float $c) => round($c, 2))->all(),
                // Podíl se počítá jen v měně rozpočtu — sečíst přes měny by znamenalo
                // hádat kurz, a procento z hádaného čísla je hádané dvakrát.
                'main' => round($radek['currencies'][$budget->currency] ?? 0, 2),
            ])
            ->sortByDesc('main')
            ->values()
            ->all();
    }

    /**
     * Průběh čerpání proti plánu, den po dni.
     *
     * Měsíční sloupce řeknou, že srpen vyšel dráž než červenec. Neřeknou ale to, na co
     * se člověk v cizině ptá průběžně: jsem teď napřed, nebo pozadu? Odpověď je v tom,
     * jak rychle ubývá plán — a to je vidět teprve na křivce, ne na číslech.
     *
     * Kreslí se zbývající částka, ne utracená. Klesající čára, která má trefit nulu
     * přesně na konci období, je otázka „vyjde to" převedená do obrázku: kdo je pod
     * ideální čarou, má rezervu, kdo nad ní, utrácí rychleji, než plán unese.
     *
     * Ke skutečnosti se připojuje i odhad do konce, protože průběh sám o sobě odpovídá
     * jen na to, co bylo. Je vedený zvlášť, aby se nedal splést s tím, co se opravdu
     * stalo — jedno jsou zapsané výdaje, druhé dopočet.
     *
     * @return array<string, mixed>|null
     */
    public function burndown(Budget $budget, ?Carbon $today = null): ?array
    {
        $today ??= Carbon::today();

        if ($budget->ends_on === null) {
            return null;
        }

        $rozvaha = $this->allowance($budget, $budget->entries->where('kind', 'expense'), $today);
        $plan = $rozvaha['planned_total'];

        if ($plan <= 0) {
            // Bez plánu není proti čemu čerpání poměřovat a graf by byl jen čára k nule.
            return null;
        }

        $zacatek = $budget->starts_on->copy()->startOfDay();
        $konec = $budget->ends_on->copy()->startOfDay();
        $dniCelkem = max(1, (int) $zacatek->diffInDays($konec));

        // Výdaje v měně rozpočtu po dnech. Jiné měny se nesčítají, stejně jako všude jinde.
        $poDnech = $budget->entries
            ->where('kind', 'expense')
            ->where('currency', $budget->currency)
            ->groupBy(fn (BudgetEntry $e) => $e->spent_on->toDateString())
            ->map(fn (Collection $den) => (float) $den->sum('amount'));

        $body = [];
        $narusto = 0.0;
        $dnesniIndex = null;

        for ($i = 0; $i <= $dniCelkem; $i++) {
            $den = $zacatek->copy()->addDays($i);
            $jeBudoucnost = $den->greaterThan($today);

            if (! $jeBudoucnost) {
                $narusto += $poDnech[$den->toDateString()] ?? 0.0;
                $dnesniIndex = $i;
            }

            $body[] = [
                'day' => $i,
                'date' => $den->toDateString(),
                // Zbývá ze skutečnosti — jen do dneška. Dál už by to nebyl záznam.
                'left' => $jeBudoucnost ? null : round($plan - $narusto, 2),
                // Ideální tempo: rovnoměrné čerpání od plánu k nule.
                'pace' => round($plan * (1 - $i / $dniCelkem), 2),
            ];
        }

        // Odhad do konce, navázaný na poslední skutečný bod. Pravidelné platby známe
        // jmenovitě, zbytek je dosavadní tempo nepravidelných výdajů.
        $vyhled = $this->outlook($budget, $today);

        if ($vyhled !== null && $dnesniIndex !== null) {
            $odsud = $plan - $narusto;
            $doKonce = $vyhled['recurring_expense'] + $vyhled['variable_estimate'];
            $zbyvaDnu = max(1, $dniCelkem - $dnesniIndex);

            foreach ($body as $i => $bod) {
                if ($i < $dnesniIndex) {
                    continue;
                }

                $body[$i]['forecast'] = round($odsud - $doKonce * (($i - $dnesniIndex) / $zbyvaDnu), 2);
            }
        }

        return [
            'currency' => $budget->currency,
            'planned_total' => $plan,
            'days_total' => $dniCelkem,
            'today_index' => $dnesniIndex,
            'starts_on' => $zacatek->toDateString(),
            'ends_on' => $konec->toDateString(),
            // Kladné číslo znamená rezervu proti ideálnímu tempu, záporné náskok ve výdajích.
            'vs_pace' => $dnesniIndex !== null
                ? round(($plan - $narusto) - ($plan * (1 - $dnesniIndex / $dniCelkem)), 2)
                : null,
            'points' => $body,
        ];
    }

    /** Jak daleko dopředu se vypisují jednotlivé nadcházející platby. */
    private const VYHLED_DNI = 30;

    /** Zbytek pod tímhle podílem celého plánu se hlásí jako těsný. */
    private const TESNA_REZERVA = 0.1;

    /**
     * Data, kdy pravidelná platba do konce období ještě přijde.
     *
     * Den v měsíci se nedá použít přímo: třicátý první v únoru neexistuje a `Carbon` by
     * z něj udělal první březen, takže by platba spadla do jiného měsíce. Bere se proto
     * poslední den měsíce, pokud je kratší.
     *
     * @return list<Carbon>
     */
    private function dalsiTerminy(int $denVMesici, Carbon $today, Carbon $konec): array
    {
        $terminy = [];
        $mesic = $today->copy()->startOfMonth();

        while ($mesic->lessThanOrEqualTo($konec)) {
            $den = $mesic->copy()->day(min($denVMesici, $mesic->daysInMonth));

            if ($den->greaterThan($today) && $den->lessThanOrEqualTo($konec)) {
                $terminy[] = $den;
            }

            $mesic->addMonthNoOverflow();
        }

        return $terminy;
    }

    /**
     * Kolik za den padne na to, co se neopakuje.
     *
     * Pravidelné platby se do tempa nepočítají — ty už jsou v předpovědi jednou, jmenovitě,
     * a započítat je podruhé přes průměr by nájem zaplatilo dvakrát.
     */
    private function tempoNepravidelnych(Budget $budget, Carbon $today): float
    {
        $od = $budget->starts_on;
        $dni = max(1, (int) $od->diffInDays($today->min($budget->ends_on ?? $today), false));

        $nepravidelne = $budget->entries
            ->where('kind', 'expense')
            ->where('is_recurring', false)
            ->where('currency', $budget->currency)
            ->sum('amount');

        return round(((float) $nepravidelne) / $dni, 4);
    }

    /**
     * Dva měsíce vedle sebe, po kategoriích.
     *
     * Součet za měsíc řekne, že se utratilo víc; teprve rozpad po kategoriích řekne za co,
     * a to je jediná informace, se kterou se dá něco udělat. Bere se poslední měsíc, ve
     * kterém něco je, a ten před ním — ne aktuální proti minulému: rozpočet otevřený
     * třetího v měsíci by porovnával tři dny s třiceti a vypadal by jako zázrak.
     *
     * @return array<string, mixed>|null
     */
    public function comparison(Budget $budget): ?array
    {
        $budget->loadMissing(['categories', 'entries.category']);

        $vydaje = $budget->entries->where('kind', 'expense')->where('currency', $budget->currency);

        $mesice = $vydaje
            ->map(fn (BudgetEntry $e) => $e->spent_on->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        if ($mesice->count() < 2) return null;

        $novy = $mesice->last();
        $stary = $mesice[$mesice->count() - 2];

        $soucet = function (string $mesic, ?int $kategorie) use ($vydaje) {
            return round((float) $vydaje
                ->filter(fn (BudgetEntry $e) => $e->spent_on->format('Y-m') === $mesic
                    && $e->budget_category_id === $kategorie)
                ->sum('amount'), 2);
        };

        // I kategorie bez jediné položky patří do tabulky: nula proti tisícovce je
        // změna, kterou chce člověk vidět nejvíc.
        $radky = $budget->categories->map(fn ($category) => [
            'name' => $category->name,
            'color' => $category->color,
            'previous' => $soucet($stary, $category->id),
            'current' => $soucet($novy, $category->id),
        ])->values()->all();

        // Položky bez kategorie se nesmí ztratit, jinak by řádky nedaly součet.
        $bezKategorie = ['previous' => $soucet($stary, null), 'current' => $soucet($novy, null)];
        if ($bezKategorie['previous'] > 0 || $bezKategorie['current'] > 0) {
            $radky[] = ['name' => 'Bez kategorie', 'color' => null] + $bezKategorie;
        }

        foreach ($radky as $i => $radek) {
            $radky[$i]['diff'] = round($radek['current'] - $radek['previous'], 2);
            $radky[$i]['diff_percent'] = $radek['previous'] > 0
                ? (int) round(($radek['current'] - $radek['previous']) / $radek['previous'] * 100)
                : null;
        }

        // Největší změny nahoru — kvůli tomu se tabulka otevírá.
        usort($radky, fn ($a, $b) => abs($b['diff']) <=> abs($a['diff']));

        $celkemStary = array_sum(array_column($radky, 'previous'));
        $celkemNovy = array_sum(array_column($radky, 'current'));

        return [
            'previous_month' => $stary,
            'current_month' => $novy,
            'currency' => $budget->currency,
            'rows' => $radky,
            'total_previous' => round($celkemStary, 2),
            'total_current' => round($celkemNovy, 2),
            'total_diff' => round($celkemNovy - $celkemStary, 2),
        ];
    }

    /** @param Collection<int, BudgetEntry> $entries */
    private function byCurrency(Collection $entries): array
    {
        return $entries
            ->groupBy('currency')
            ->map(fn (Collection $skupina) => round((float) $skupina->sum('amount'), 2))
            ->all();
    }

    /**
     * Požádá partnera o peníze.
     *
     * Notifikace odchází hned: žádost, o které se druhý dozví, až sám otevře aplikaci,
     * je k ničemu právě ve chvíli, kdy na ni docházejí peníze.
     */
    public function requestMoney(GallerySpace $space, User $from, User $to, array $data): MoneyRequest
    {
        $request = MoneyRequest::create([
            'gallery_space_id' => $space->id,
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'reason' => $data['reason'] ?? null,
            'status' => MoneyRequest::STATUS_PENDING,
        ]);

        $castka = number_format((float) $data['amount'], 2, ',', ' ') . ' ' . strtoupper($data['currency']);

        $to->notify(new GalleryNotification(
            'finance.request',
            $from->name . ' žádá o ' . $castka . ($data['reason'] ?? null ? ' — ' . $data['reason'] : ''),
            '/rozpocty#zadosti',
            '💸',
            ['money_request_uuid' => $request->uuid],
        ));

        return $request;
    }

    /**
     * Vyřízení žádosti.
     *
     * Kurz a skutečně poslaná částka se zapisují tady, protože teprve teď je někdo zná.
     */
    public function respond(MoneyRequest $request, User $user, string $status, array $data = []): MoneyRequest
    {
        abort_unless($request->isOpen(), 422, 'O této žádosti už bylo rozhodnuto.');

        $request->forceFill([
            'status' => $status,
            'responded_at' => now(),
            'response_note' => $data['response_note'] ?? null,
            'settled_amount' => $data['settled_amount'] ?? null,
            'settled_currency' => isset($data['settled_currency']) ? strtoupper($data['settled_currency']) : null,
            'exchange_rate' => $data['exchange_rate'] ?? null,
        ])->save();

        $request->requester?->notify(new GalleryNotification(
            'finance.request',
            $status === MoneyRequest::STATUS_SENT
                ? $user->name . ' peníze poslal/a.'
                : $user->name . ' žádost zamítl/a.',
            '/rozpocty#zadosti',
            $status === MoneyRequest::STATUS_SENT ? '✅' : '↩️',
            ['money_request_uuid' => $request->uuid],
        ));

        return $request;
    }
}
