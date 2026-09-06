<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rozbory nad financemi: obálka pro sebe, vlastní inflace, sezónní fondy,
 * cena cesty, cena odkladu, nečekané výdaje, odhad proti skutečnosti a co
 * ta útrata znamenala.
 *
 * Žádná nová tabulka — všechno se počítá z transakcí, limitů, cílů a lhůt,
 * které dvojice už má.
 *
 * Co se spočítat nedá, se **neposílá** — obrazovka pak drží ukázku, a to je
 * pořád lepší než vymyšlené číslo, podle kterého se dvojice rozhoduje.
 */
class FinanceRozbory implements PoskytovatelObsahu
{
    /** Kolik měsíců zpátky kreslí obálka a vlastní inflace. */
    private const MESICU = 6;

    private const MESICE = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    public function skupina(): string
    {
        return 'rozbory';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('transactions')) {
            return [];
        }

        return array_filter([
            'ENV' => $this->obalka($prostor),
            'INFL' => $this->inflace($prostor),
            'SEASON' => $this->fondy($prostor),
            'TRIPCOST' => $this->cenyCest($prostor),
            'DELAY' => $this->cenaOdkladu($prostor),
            'SURPRISE' => $this->necekane($prostor),
            'EST' => $this->odhadySkutecnost($prostor),
            'COSTMEAN' => $this->coToZnamenalo($prostor),
            'P60' => $predpoved = $this->predpoved($prostor),
            'SCEN' => $predpoved ? $this->scenare($prostor) : [],
            'HORIZON' => $this->horizont($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Scénáře k šedesátidenní předpovědi.
     *
     * Katalog, ale jedna poznámka v něm jmenovala člověka: „Makinka jde na
     * částečný úvazek." U jiné dvojice to bylo jméno někoho cizího. Jméno
     * se proto bere z prostoru — a když se vzít nedá, věta ho neobsahuje.
     *
     * Bez předpovědi se neposílají: samotné popisky nemají co ovládat.
     *
     * @return list<array<string, string>>
     */
    private function scenare(GallerySpace $prostor): array
    {
        /*
         * Popisky říkají, co scénář opravdu počítá.
         *
         * „Makinka jde na částečný úvazek" u výpočtu, který snižuje **všechny**
         * příjmy o pětinu, bylo tvrzení navíc — a ještě o cizím člověku.
         */
        return [
            ['key' => 'income', 'label' => 'Příjem o 20 % nižší', 'note' => 'Všechny pravidelné příjmy o pětinu níž.'],
            ['key' => 'loan', 'label' => 'Nová splátka 4 900 / měs.', 'note' => 'Dvakrát za dva měsíce ubude splátka.'],
            ['key' => 'parent', 'label' => 'Rodičovská za půl roku', 'note' => 'Nižší z pravidelných příjmů klesne na 40 %.'],
            ['key' => 'save', 'label' => 'Zrušit dvě předplatná', 'note' => 'Úspora 438 Kč měsíčně.'],
        ];
    }

    /**
     * Předpověď na šedesát dní: `{ start, daily, events: [{ d, label, amt, move }] }`.
     *
     * Obrazovka o sobě říká: „Počítá se s pevnými platbami, oběma mzdami
     * a průměrnou denní útratou. Nic se nemodeluje ručně." Přesně tak se to
     * teď počítá — z peněženek, pravidelných plateb a skutečných transakcí.
     *
     * `move` znamená „dá se s tím pohnout": mzda ne, předplatné ano. Pozná se
     * podle typu položky, ne podle názvu.
     *
     * Bez pravidelných plateb se **neposílá nic**. Čára, která šedesát dní jen
     * rovnoměrně klesá, není předpověď, je to odečítání.
     *
     * @return array<string, mixed>
     */
    private function predpoved(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('finance_recurring') || ! Schema::hasTable('wallets')) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();

        $platby = DB::table('finance_recurring')
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $dnes->toDateString()))
            ->whereNotNull('day_of_month')
            ->get(['name', 'type', 'amount', 'day_of_month']);

        if ($platby->isEmpty()) {
            return [];
        }

        $zustatek = $this->zustatek($prostor);
        $denne = $this->prumernaDenniUtrata($prostor);

        /*
         * Den v měsíci na pořadí v šedesátidenní ose.
         *
         * Platba splatná 4. připadne na dva dny — jednou v tomhle měsíci
         * a jednou v příštím. Kdyby se bral jen nejbližší výskyt, druhá půlka
         * osy by byla podezřele klidná.
         */
        $udalosti = [];

        foreach ($platby as $p) {
            $prijem = $p->type === 'income';
            $castka = (int) round(abs((float) $p->amount)) * ($prijem ? 1 : -1);

            if ($castka === 0) {
                continue;
            }

            for ($mesic = 0; $mesic <= 2; $mesic++) {
                $den = $dnes->addMonths($mesic)->startOfMonth();
                $splatnost = $den->addDays(min((int) $p->day_of_month, (int) $den->daysInMonth) - 1);
                $poradi = (int) $dnes->diffInDays($splatnost, false);

                if ($poradi < 1 || $poradi > 60) {
                    continue;
                }

                $udalosti[] = [
                    'd' => $poradi,
                    'label' => $p->name,
                    'amt' => $castka,
                    // Mzdou se pohnout nedá, splátkou nájmu prakticky taky ne.
                    // Posunout jde to, co si dvojice objednala sama.
                    'move' => ! $prijem,
                ];
            }
        }

        if ($udalosti === []) {
            return [];
        }

        usort($udalosti, fn (array $a, array $b) => $a['d'] <=> $b['d']);

        return [
            'start' => $zustatek,
            'daily' => $denne,
            'events' => $udalosti,
        ];
    }

    /**
     * Zůstatek na společných účtech k dnešku, zaokrouhlený na koruny.
     *
     * Hotovost se nepočítá: předpověď je o tom, co odejde z účtu, a peníze
     * v peněžence žádnou pevnou platbu nezaplatí.
     */
    private function zustatek(GallerySpace $prostor): int
    {
        $penezenky = DB::table('wallets')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('kind', '!=', 'cash')
            ->get(['id', 'opening_balance']);

        if ($penezenky->isEmpty()) {
            return 0;
        }

        $ids = $penezenky->pluck('id')->all();
        $zustatek = (float) $penezenky->sum('opening_balance');

        $pohyby = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('state', ['approved', 'settled'])
            ->where(fn ($q) => $q->whereIn('wallet_from_id', $ids)->orWhereIn('wallet_to_id', $ids))
            ->get(['wallet_from_id', 'wallet_to_id', 'amount_from', 'amount_to', 'fee_amount']);

        foreach ($pohyby as $p) {
            if (in_array($p->wallet_to_id, $ids, true)) {
                $zustatek += (float) $p->amount_to;
            }

            if (in_array($p->wallet_from_id, $ids, true)) {
                $zustatek -= (float) $p->amount_from + (float) $p->fee_amount;
            }
        }

        return (int) round($zustatek);
    }

    /**
     * Průměrná denní útrata z posledních devadesáti dnů.
     *
     * Pevné platby se odečítají: v předpovědi stojí jako události ve svůj den
     * a v denním průměru by byly podruhé. Bez toho by čára klesala dvakrát
     * rychleji, než jak peníze doopravdy ubývají.
     */
    private function prumernaDenniUtrata(GallerySpace $prostor): int
    {
        $od = CarbonImmutable::now()->subDays(90)->startOfDay();

        $utrata = (float) Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', 'expense')
            ->whereIn('state', ['approved', 'settled'])
            ->where('occurred_at', '>=', $od)
            ->sum('amount_from');

        $pevne = Schema::hasTable('finance_recurring')
            ? (float) DB::table('finance_recurring')
                ->where('gallery_space_id', $prostor->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where('type', '!=', 'income')
                ->sum('amount') * 3
            : 0.0;

        return max(0, (int) round(max(0, $utrata - $pevne) / 90));
    }

    /**
     * Desetiletý horizont: `[{ what, monthly, oneOff, note }]`.
     *
     * Obrazovka bere pravidelné platby a ukazuje, na kolik vyjdou za deset let.
     * Sloupec si počítá sama; sem patří jen to, co se opravdu platí.
     *
     * `note` je věta o **té platbě**, ne o životě: od kdy běží a kolikátého
     * odchází. Vymýšlet k ní úvahu („druhé auto stojí 22 hodin denně") by
     * znamenalo mluvit za dvojici o něčem, co aplikace neví.
     *
     * @return list<array<string, mixed>>
     */
    private function horizont(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('finance_recurring')) {
            return [];
        }

        $dnes = CarbonImmutable::now();

        return DB::table('finance_recurring')
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('type', '!=', 'income')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $dnes->toDateString()))
            ->orderByDesc('amount')
            ->limit(12)
            ->get(['name', 'amount', 'day_of_month', 'starts_on'])
            ->map(function (object $p) use ($dnes) {
                $mesicne = (int) round(abs((float) $p->amount));
                $bezi = $p->starts_on ? CarbonImmutable::parse($p->starts_on) : null;
                $mesicu = $bezi && $bezi->lt($dnes) ? (int) $bezi->diffInMonths($dnes) : 0;

                return [
                    'what' => $p->name.' '.number_format($mesicne, 0, ',', ' ').' / měs.',
                    'monthly' => $mesicne,
                    // Jednorázová část se u pravidelné platby nikde nevede;
                    // dopsat odhad by znamenalo přičíst číslo, které nikdo nezadal.
                    'oneOff' => 0,
                    'note' => $mesicu >= 1
                        ? 'Platí se '.$this->pocetMesicu($mesicu).' · dohromady už '
                            .number_format($mesicne * $mesicu, 0, ',', ' ').' Kč.'
                        : 'Nová pravidelná platba.',
                ];
            })
            ->values()
            ->all();
    }

    private function pocetMesicu(int $mesicu): string
    {
        return $mesicu.' '.match (true) {
            $mesicu === 1 => 'měsíc',
            $mesicu <= 4 => 'měsíce',
            default => 'měsíců',
        };
    }

    /**
     * Cena odkladu: `[{ what, days, cost, open, route, tab }]`.
     *
     * Lhůta, která se protáhla, a co to stálo. Obojí si dvojice zapisuje sama
     * (`delay_cost`, `delay_note`) — dopočítat cenu odkladu z ničeho nejde
     * a odhadnout ji by znamenalo vyčíslit dvojici chybu, kterou nikdo
     * nezměřil.
     *
     * @return list<array<string, mixed>>
     */
    private function cenaOdkladu(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('house_dues')) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();

        return DB::table('house_dues')
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('due_on')
            ->where(fn ($q) => $q->whereNotNull('delay_cost')->orWhereNotNull('delay_note'))
            ->orderByDesc('due_on')
            ->limit(20)
            ->get()
            ->map(function (object $z) use ($dnes) {
                $termin = CarbonImmutable::parse($z->due_on)->startOfDay();
                // Vyřízené se počítá ke dni vyřízení, otevřené k dnešku.
                $konec = $z->settled_at ? CarbonImmutable::parse($z->settled_at)->startOfDay() : $dnes;

                return [
                    'what' => $z->delay_note ?: $z->what,
                    'days' => max(0, (int) $termin->diffInDays($konec, false)),
                    'cost' => (int) ($z->delay_cost ?? 0),
                    'open' => $z->settled_at === null,
                    'route' => 'x-domacnost',
                    'tab' => 'dues',
                ];
            })
            ->filter(fn (array $r) => $r['days'] > 0)
            ->values()
            ->all();
    }

    /**
     * Nečekané výdaje: `[{ what, cost, month }]`.
     *
     * Nečekaný je ten, který **nemá kategorii s limitem** — na co si dvojice
     * hranici nedala, s tím nepočítala. Malé částky se nepočítají: nečekaný
     * výdaj za osmdesát korun není nečekaný výdaj, je to oběd.
     *
     * @return list<array<string, mixed>>
     */
    private function necekane(GallerySpace $prostor): array
    {
        $hranice = $this->hranice($prostor);

        if ($hranice === 0.0) {
            return [];
        }

        $sLimitem = Schema::hasTable('budget_category_limits')
            ? DB::table('budget_category_limits as l')
                ->join('budgets as r', 'r.id', '=', 'l.budget_id')
                ->where('r.gallery_space_id', $prostor->id)
                ->pluck('finance_category_id')
            : collect();

        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('occurred_at', '>=', CarbonImmutable::now()->startOfYear())
            // Výdaj se ukládá kladně a znaménko dělá `type`; záporná částka
            // je vratka, a ta je stejně velká událost jako nákup.
            ->where(fn ($q) => $q->where('amount_from', '>=', $hranice)
                ->orWhere('amount_from', '<=', -$hranice))
            ->when($sLimitem->isNotEmpty(), fn ($q) => $q->where(
                fn ($v) => $v->whereNull('category_id')->orWhereNotIn('category_id', $sLimitem),
            ))
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['description', 'counterparty', 'amount_from', 'occurred_at'])
            ->map(fn (Transaction $t) => [
                'what' => $t->description ?: ($t->counterparty ?: 'Bez popisu'),
                'cost' => (int) round(abs((float) $t->amount_from)),
                'month' => self::MESICE[CarbonImmutable::parse($t->occurred_at)->month],
            ])
            ->values()
            ->all();
    }

    /**
     * Kolik je „velký" výdaj u téhle dvojice.
     *
     * Ne pevná tisícovka: u někoho je nečekaných pět set, u někoho pět tisíc.
     * Bere se desetinásobek běžné útraty — medián, ne průměr, aby to jeden
     * velký nákup neposunul.
     */
    private function hranice(GallerySpace $prostor): float
    {
        $castky = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('occurred_at', '>=', CarbonImmutable::now()->subYear())
            ->pluck('amount_from')
            ->map(fn ($c) => abs((float) $c))
            ->filter()
            ->sort()
            ->values();

        if ($castky->count() < 5) {
            return 0.0;
        }

        return max(1000.0, $castky[(int) floor($castky->count() / 2)] * 10);
    }

    /**
     * Odhad proti skutečnosti: `[{ name, who, unit, est, real }]`.
     *
     * Cíl rozpočtu je odhad („na dovolenou 45 000"), útrata v jeho kategorii
     * je skutečnost. Bez obojího se řádek neposílá — polovina té dvojice
     * neříká nic.
     *
     * @return list<array<string, mixed>>
     */
    private function odhadySkutecnost(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('budget_category_limits')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        $limity = DB::table('budget_category_limits as l')
            ->join('budgets as r', 'r.id', '=', 'l.budget_id')
            ->join('finance_categories as k', 'k.id', '=', 'l.finance_category_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->get(['l.finance_category_id', 'l.amount', 'k.name', 'r.created_by']);

        if ($limity->isEmpty()) {
            return [];
        }

        $utraceno = $this->utracenoPoKategoriich(
            $prostor,
            CarbonImmutable::now()->startOfYear(),
            CarbonImmutable::now(),
        );

        return $limity
            ->map(fn (object $l) => [
                'name' => $l->name,
                'who' => $jmena[$l->created_by] ?? 'spolu',
                'unit' => 'kc',
                'est' => (int) round((float) $l->amount),
                'real' => (int) round((float) ($utraceno[$l->finance_category_id] ?? 0)),
            ])
            // Kategorie, do které se letos nesáhlo, není odhad — je to plán.
            ->filter(fn (array $r) => $r['real'] > 0 && $r['est'] > 0)
            ->sortByDesc(fn (array $r) => abs($r['real'] - $r['est']))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Co ta útrata znamenala: `[název, částka, podíl, věta, ikona]`.
     *
     * Podíl na ročním rozpočtu je to jediné, co k tomu aplikace umí říct sama.
     * Věty typu „za rok je z toho letenka do Lisabonu" se **nevymýšlí** —
     * místo nich stojí, kolik z celku to je a kolikátá největší položka to je.
     *
     * @return list<array<int, mixed>>
     */
    private function coToZnamenalo(GallerySpace $prostor): array
    {
        $od = CarbonImmutable::now()->startOfYear();

        $poKategoriich = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('occurred_at', '>=', $od)
            ->with('category:id,name,icon')
            ->get(['category_id', 'amount_from'])
            ->groupBy(fn (Transaction $t) => $t->category?->name ?? 'Nezařazeno');

        if ($poKategoriich->isEmpty()) {
            return [];
        }

        $celkem = (float) $poKategoriich->flatten()->sum(fn (Transaction $t) => abs((float) $t->amount_from));

        if ($celkem <= 0) {
            return [];
        }

        return $poKategoriich
            ->map(fn (Collection $pohyby, string $nazev) => [
                'nazev' => $nazev,
                'castka' => (float) $pohyby->sum(fn (Transaction $t) => abs((float) $t->amount_from)),
                'pocet' => $pohyby->count(),
                'ikona' => $this->ikonaKategorie($pohyby->first()?->category?->icon),
            ])
            ->sortByDesc('castka')
            ->take(8)
            ->values()
            ->map(function (array $k) use ($celkem) {
                $podil = $k['castka'] / $celkem * 100;

                return [
                    $k['nazev'],
                    (int) round($k['castka']),
                    str_replace('.', ',', (string) round($podil, 1)).' % letošních výdajů',
                    $this->pocet($k['pocet'], 'platba', 'platby', 'plateb').' za tenhle rok.',
                    $k['ikona'],
                ];
            })
            ->all();
    }

    /**
     * Utraceno po kategoriích za dané období.
     *
     * @return array<int, float>
     */
    private function utracenoPoKategoriich(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do): array
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->whereBetween('occurred_at', [$od, $do])
            ->selectRaw('category_id, SUM(ABS(amount_from)) AS castka')
            ->groupBy('category_id')
            ->pluck('castka', 'category_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function ikonaKategorie(?string $ikona): string
    {
        return $ikona ? 'ph-'.ltrim($ikona, 'ph-') : 'ph-tag';
    }

    /**
     * Obálka jen pro sebe: `{ limit, months: [{ m, a, k }] }`.
     *
     * Jediná položka rozpočtu bez odůvodnění — proto se počítá z kategorie,
     * kterou si dvojice takhle pojmenovala, a ne z odhadu. Bez ní se neposílá
     * nic: vymyslet, kolik si kdo „smí vzít, aniž by se ptal", by bylo to
     * poslední, co by měl dělat server.
     *
     * @return array<string, mixed>|null
     */
    private function obalka(GallerySpace $prostor): ?array
    {
        $kategorie = $this->osobniKategorie($prostor);

        if (! $kategorie) {
            return null;
        }

        $dvojice = $this->dvojice($prostor);
        $od = CarbonImmutable::now()->startOfMonth()->subMonths(self::MESICU - 1);

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('category_id', $kategorie->id)
            ->where('occurred_at', '>=', $od)
            ->get(['occurred_at', 'amount_from', 'amount_to', 'payer_partner_id']);

        $mesice = [];

        for ($i = 0; $i < self::MESICU; $i++) {
            $mesic = $od->addMonths($i);

            $vMesici = $pohyby->filter(
                fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->isSameMonth($mesic),
            );

            $mesice[] = [
                'm' => self::MESICE[$mesic->month],
                'a' => $this->soucet($vMesici, $dvojice[0]),
                'k' => $this->soucet($vMesici, $dvojice[1]),
            ];
        }

        return [
            'limit' => (int) ($this->limitKategorie($prostor, $kategorie->id) ?: 0),
            'months' => $mesice,
        ];
    }

    /**
     * Vlastní inflace: `{ name, y25, y26, qty, cat }`.
     *
     * Ne ta ze zpráv — tahle se počítá z toho, co dvojice doopravdy kupuje
     * pořád dokola. Bere se **medián**, ne průměr: jeden velký nákup by jinak
     * z rohlíků udělal luxusní zboží.
     *
     * @return list<array<string, mixed>>
     */
    private function inflace(GallerySpace $prostor): array
    {
        $letos = CarbonImmutable::now()->year;

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('occurred_at', '>=', CarbonImmutable::create($letos - 1, 1, 1))
            ->with('category:id,name')
            ->get();

        return $pohyby
            ->filter(fn (Transaction $t) => trim((string) $t->description) !== '')
            ->groupBy(fn (Transaction $t) => mb_strtolower(trim((string) $t->description)))
            ->map(function (Collection $stejne) use ($letos) {
                $rok = fn (int $r) => $stejne
                    ->filter(fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->year === $r)
                    ->map(fn (Transaction $t) => abs((float) ($t->amount_from ?? $t->amount_to ?? 0)))
                    ->filter()
                    ->values();

                $loni = $rok($letos - 1);
                $ted = $rok($letos);

                // Bez obou roků není co porovnávat.
                if ($loni->isEmpty() || $ted->isEmpty()) {
                    return null;
                }

                $prvni = $stejne->first();

                return [
                    'name' => trim((string) $prvni->description),
                    'y25' => (int) round($loni->median()),
                    'y26' => (int) round($ted->median()),
                    'qty' => $ted->count(),
                    'cat' => mb_strtolower((string) ($prvni->category?->name ?? 'ostatní')),
                ];
            })
            ->filter()
            // Nejdřív to, co se opakuje nejčastěji — na tom je zdražení vidět.
            ->sortByDesc('qty')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Sezónní fondy: `{ id, name, months, target, saved, per, icon, note }`.
     *
     * @return list<array<string, mixed>>
     */
    private function fondy(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('budget_goals')) {
            return [];
        }

        $dnes = CarbonImmutable::now();

        return DB::table('budget_goals as c')
            ->join('budgets as r', 'r.id', '=', 'c.budget_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->orderBy('c.sort_order')
            ->get(['c.uuid', 'c.name', 'c.target_amount', 'c.saved_amount', 'c.target_on', 'c.note'])
            ->map(function (object $c) use ($dnes) {
                $do = $c->target_on ? CarbonImmutable::parse($c->target_on) : null;
                // Nahoru: začatý měsíc se ještě počítá. Zaokrouhlení dolů by
                // z fondu udělalo vyšší měsíční částku, než kolik je potřeba.
                $zbyva = $do ? max(1, (int) ceil($dnes->diffInMonths($do))) : 12;
                $chybi = max(0, (float) $c->target_amount - (float) $c->saved_amount);

                return [
                    'id' => $c->uuid,
                    'name' => $c->name,
                    'months' => $do ? self::MESICE[$do->month].' '.$do->year : 'průběžně',
                    'target' => (int) $c->target_amount,
                    'saved' => (int) $c->saved_amount,
                    // Kolik měsíčně, aby to do termínu vyšlo — ne uložené číslo,
                    // které by po každém vkladu bylo o kus vedle.
                    'per' => (int) round($chybi / $zbyva),
                    'icon' => $this->ikonaFondu($c->name),
                    'note' => (string) ($c->note ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Cena cesty: `{ total, days, people, prev, prevPerDay, items }`.
     *
     * Porovnává se s **předchozí cestou**, ne s průměrem — „o tisíc na den víc
     * než ve Vídni" je věta, se kterou se dá něco dělat.
     *
     * @return array<string, array<string, mixed>>
     */
    private function cenyCest(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('trips') || ! Schema::hasTable('trip_expenses')) {
            return [];
        }

        $cesty = DB::table('trips')
            ->where('gallery_space_id', $prostor->id)
            ->whereDate('end_date', '<=', CarbonImmutable::now())
            ->orderByDesc('start_date')
            ->limit(12)
            ->get();

        if ($cesty->isEmpty()) {
            return [];
        }

        $utraty = DB::table('trip_expenses')
            ->whereIn('trip_id', $cesty->pluck('id'))
            ->where('state', '!=', 'planned')
            ->get()
            ->groupBy('trip_id');

        $lidi = max(2, $prostor->members()->count());
        $vysledek = [];
        $predchozi = null;

        // Odzadu, aby každá cesta znala tu předchozí.
        foreach ($cesty->reverse() as $c) {
            $moje = $utraty[$c->id] ?? collect();

            if ($moje->isEmpty()) {
                continue;
            }

            $od = CarbonImmutable::parse($c->start_date);
            $dnu = max(1, (int) $od->diffInDays(CarbonImmutable::parse($c->end_date)) + 1);
            $celkem = (int) $moje->sum('amount');

            $vysledek[$this->klic($c->name, $vysledek)] = [
                'total' => $celkem,
                'days' => $dnu,
                'people' => $lidi,
                'prev' => $predchozi['name'] ?? '',
                'prevPerDay' => $predchozi['perDay'] ?? 0,
                'items' => $moje
                    ->groupBy('category')
                    ->map(fn (Collection $co, $kategorie) => [
                        'name' => $this->kategorie((string) $kategorie),
                        'amount' => (int) $co->sum('amount'),
                        'note' => $this->pocet($co->count(), 'položka', 'položky', 'položek'),
                    ])
                    ->sortByDesc('amount')
                    ->values()
                    ->all(),
            ];

            $predchozi = ['name' => $c->name, 'perDay' => (int) round($celkem / $dnu)];
        }

        return $vysledek;
    }

    // ——— dílky ———

    /**
     * Kategorie, kterou si dvojice vede jako osobní obálku.
     *
     * Pozná se podle jména — vlastní příznak na to v aplikaci není a vymýšlet
     * ho jen kvůli jedné obrazovce by bylo horší než se zeptat názvu.
     */
    private function osobniKategorie(GallerySpace $prostor): ?object
    {
        if (! Schema::hasTable('finance_categories')) {
            return null;
        }

        return DB::table('finance_categories')
            ->where('gallery_space_id', $prostor->id)
            ->where(function ($q) {
                foreach (['obálka', 'obalka', 'osobní', 'osobni', 'kapesné', 'kapesne'] as $slovo) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$slovo.'%']);
                }
            })
            ->first(['id', 'name']);
    }

    private function limitKategorie(GallerySpace $prostor, int $kategorie): float
    {
        if (! Schema::hasTable('budget_category_limits')) {
            return 0;
        }

        return (float) DB::table('budget_category_limits as l')
            ->join('budgets as r', 'r.id', '=', 'l.budget_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->where('l.finance_category_id', $kategorie)
            ->orderByDesc('r.id')
            ->value('l.amount');
    }

    /**
     * Dvojice jako partneři plateb — zakladatel prostoru první.
     *
     * @return array<int, int|null>
     */
    private function dvojice(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('partners')) {
            return [null, null];
        }

        $lide = $prostor->members()
            ->orderByRaw('users.id = ? desc', [$prostor->owner_id])
            ->pluck('users.id');

        $partneri = DB::table('partners')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('user_id', $lide)
            ->pluck('id', 'user_id');

        return [$partneri[$lide[0] ?? 0] ?? null, $partneri[$lide[1] ?? 0] ?? null];
    }

    /** @param  Collection<int, Transaction>  $pohyby */
    private function soucet(Collection $pohyby, ?int $partner): int
    {
        if (! $partner) {
            return 0;
        }

        return (int) round($pohyby
            ->filter(fn (Transaction $t) => (int) $t->payer_partner_id === $partner)
            ->sum(fn (Transaction $t) => abs((float) ($t->amount_from ?? $t->amount_to ?? 0))));
    }

    // ——— formát ———

    /** @param  array<string, mixed>  $uz */
    private function klic(string $nazev, array $uz): string
    {
        $bez = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nazev);
        $zaklad = substr(strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $bez ?: $nazev)) ?: 'cesta', 0, 24);
        $klic = $zaklad;
        $poradi = 2;

        while (array_key_exists($klic, $uz)) {
            $klic = $zaklad.$poradi++;
        }

        return $klic;
    }

    private function kategorie(string $kategorie): string
    {
        return match ($kategorie) {
            'transport' => 'Doprava',
            'lodging', 'accommodation' => 'Ubytování',
            'food' => 'Jídlo venku',
            'groceries' => 'Nákupy potravin',
            'tickets', 'activities' => 'Vstupy a výlety',
            'shopping' => 'Nákupy',
            default => 'Ostatní',
        };
    }

    private function ikonaFondu(string $nazev): string
    {
        $n = mb_strtolower($nazev);

        return match (true) {
            str_contains($n, 'dovolen') || str_contains($n, 'cest') => 'ph-umbrella-simple',
            str_contains($n, 'vánoc') || str_contains($n, 'dárk') => 'ph-gift',
            str_contains($n, 'auto') || str_contains($n, 'pneu') => 'ph-car',
            str_contains($n, 'rezerv') => 'ph-shield-check',
            default => 'ph-piggy-bank',
        };
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
