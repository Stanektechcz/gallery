<?php

namespace App\Services\Obsah;

use App\Models\Budget;
use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Finance\LedgerService;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finance ve tvaru, ve kterém je kreslí prototyp.
 *
 * Obrazovky Přehled, Transakce, Rozpočty a Účty čtou `TX`, `BUD`, `FIN`, `INCOMES`
 * a `SHARED`. Dosud pocházely z ukázkových dat, takže dvojice viděla cizí nákupy
 * místo svých — a **Makinčin rozpočet na Německo, který v aplikaci je, se v nich
 * neobjevil vůbec**.
 *
 * Tvary jsou dané prototypem a nemění se: `TX` je pole na devíti pozicích,
 * `BUD.cats` na šesti. Přizpůsobuje se obsah, ne obrazovka.
 */
class Finance implements PoskytovatelObsahu
{
    /** Kolik transakcí se posílá. Obrazovka jich ukáže dvacítky, ne tisíce. */
    private const TRANSAKCI = 120;

    /**
     * Do téhle priority je položka „nedotknutelná".
     *
     * Priorita se v aplikaci řadí vzestupně — nižší číslo dostane peníze první.
     * Nájem má 10, volný čas 90.
     */
    private const PEVNE = 10;

    public function __construct(private readonly LedgerService $kniha) {}

    public function skupina(): string
    {
        return 'finance';
    }

    /**
     * Nic. `FIN` má vedle účtů ještě splátky, upozornění, pravidla a importy,
     * které se počítají jinde; `BUD` zase části, které aplikace nevede. Smazat
     * je znamená prázdné obrazovky, ne pravdu.
     */
    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $rozpocet = $this->rozpocet($prostor);
        $penezenky = $this->penezenky($prostor);
        $pohyby = $this->pohyby($prostor);

        /*
         * Buď finance, nebo ukázka — nic mezi tím.
         *
         * Kdyby se posílala jen neprázdná kolekce, vypadalo by to takhle: rozpočet
         * skutečný, ale pod ním dvacet vymyšlených nákupů, protože kniha je zatím
         * prázdná. Jakmile má dvojice finance založené, posílá se všechno —
         * i prázdný seznam transakcí, protože „zatím nic" je pravda, kdežto cizí
         * nákupy jsou lež.
         */
        if ($rozpocet === null && $penezenky->isEmpty() && $pohyby->isEmpty()) {
            return [];
        }

        return [
            'TX' => $this->transakce($pohyby),
            'BUD' => $rozpocet ? $this->rozpocetVen($prostor, $rozpocet) : null,
            'FIN' => ['accounts' => $this->ucty($prostor, $penezenky)],
            // Totéž číslo jako v hlavičce rozpočtu — dvě různá by si na dvou
            // obrazovkách protiřečila.
            'INCOMES' => $rozpocet ? (int) round($this->mesicniPrijem($rozpocet)) : 0,
            'SHARED' => $this->pevneNaklady($rozpocet),
            /*
             * Měna, ve které se kreslí částky.
             *
             * Prototyp měl v jediném formátovači natvrdo „Kč". Makinčin rozpočet
             * na Německo je v eurech, takže by obrazovka u každé částky psala
             * měnu, ve které rozpočet není — a rozhodovalo by se podle toho.
             */
            'MENA' => $this->znakMeny($rozpocet?->currency ?: 'CZK'),
        ];
    }

    // ——— transakce ———

    /** @return Collection<int, Transaction> */
    private function pohyby(GallerySpace $prostor): Collection
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->with(['category:id,name,icon', 'walletFrom:id,name', 'walletTo:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(self::TRANSAKCI)
            ->get();
    }

    /**
     * `[id, datum, popis, kategorie, částka, účet, ikona, meta, poznámka]`
     *
     * Záporná částka je výdaj — tak to prototyp kreslí a podle znaménka volí barvu.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return list<array<int, mixed>>
     */
    private function transakce(Collection $pohyby): array
    {
        return $pohyby->map(function (Transaction $t) {
            $vydaj = $t->type !== 'income';
            $castka = (float) ($t->amount_from ?? $t->amount_to ?? 0);

            return [
                $t->uuid,
                $this->den($t->occurred_at ?? $t->booked_on),
                $t->description ?: ($t->counterparty ?: 'Bez popisu'),
                $t->category?->name ?? 'Nezařazeno',
                (int) round($vydaj ? -abs($castka) : abs($castka)),
                $t->walletFrom?->name ?? $t->walletTo?->name ?? '—',
                $this->ikona($t->category?->icon),
                // Meta drží prototyp jako volný objekt; účtenka je jediné, co čte.
                (object) array_filter(['receipt' => $t->receipt_media_id ? 1 : null]),
                // Devátá pozice je volná poznámka. Kniha pro ni sloupec nemá,
                // takže se posílá místo, kde se platilo — nebo nic.
                (string) ($t->place ?? ''),
            ];
        })->values()->all();
    }

    // ——— rozpočet ———

    private function rozpocet(GallerySpace $prostor): ?Budget
    {
        return Budget::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            // Běžící rozpočet má přednost před tím, co skončilo nebo teprve začne.
            ->orderByRaw('CASE WHEN starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?) THEN 0 ELSE 1 END', [now(), now()])
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * `{ month, today, days, income, plan, surplus, cats: [[název, plán, utraceno, ikona, poznámka, štítek]] }`
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<string, mixed>
     */
    private function rozpocetVen(GallerySpace $prostor, Budget $rozpocet): array
    {
        $dnes = CarbonImmutable::today();
        $limity = $this->limity($rozpocet);
        $mena = $rozpocet->currency ?: 'CZK';

        /*
         * Limity jsou za celé období, obrazovka je měsíční.
         *
         * Makinčin rozpočet má u ubytování 1 680 € — šest měsíců po 280. Ukázat to
         * jako měsíční limit by znamenalo tvrdit, že na nájem má šestkrát víc,
         * než má. Dělí se proto počtem měsíců, které rozpočet pokrývá.
         */
        $mesicu = max(1, $rozpocet->monthsCovered());
        $naMesic = fn (float $castka) => $castka / $mesicu;

        // Utraceno se počítá z knihy, ne z vlastních položek rozpočtu — jinak by
        // obrazovka ukazovala plán, který nikdo neporovnal se skutečností.
        $utraceno = $this->utracenoPoKategoriich($prostor, $dnes->startOfMonth(), $dnes->endOfMonth());

        $prijem = $this->mesicniPrijem($rozpocet);
        $plan = $naMesic((float) $limity->sum('amount'));

        return [
            'month' => $this->mesic($dnes),
            'today' => $dnes->day,
            'days' => $dnes->daysInMonth,
            'income' => (int) round($prijem),
            'plan' => (int) round($plan),
            'surplus' => (int) round($prijem - $plan),
            'cats' => $limity->map(function (object $limit) use ($utraceno, $mena, $naMesic) {
                $skutecnost = (float) ($utraceno[$limit->finance_category_id] ?? 0);
                $limitMesicne = $naMesic((float) $limit->amount);

                return [
                    $limit->nazev ?? 'Nezařazeno',
                    (int) round($limitMesicne),
                    (int) round($skutecnost),
                    $this->ikona($limit->ikona),
                    $this->poznamkaKategorie($limitMesicne, $skutecnost, $mena),
                    // Priorita se v aplikaci řadí **vzestupně**: co má nižší číslo,
                    // dostane peníze první a shazuje se poslední. Nedotknutelné je
                    // tedy to s nejnižší, ne s nejvyšší — obráceně by obrazovka
                    // označila za jisté zrovna to, co odpadne první.
                    (int) ($limit->priority ?? 100) <= self::PEVNE ? 'nedotknutelné' : null,
                ];
            })->values()->all(),
            'months' => $this->mesice($prostor, $mena),
            'year' => $this->rok($prostor, $rozpocet),
            'goals' => $this->cile($rozpocet),
            'yearCats' => [],
        ];
    }

    /**
     * Utraceno po měsících za posledních dvanáct měsíců.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function mesice(GallerySpace $prostor, string $mena): array
    {
        $od = CarbonImmutable::today()->startOfMonth()->subMonths(11);

        $soucty = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('occurred_at', '>=', $od)
            ->get(['occurred_at', 'amount_from'])
            ->groupBy(fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->format('Y-m'))
            ->map(fn (Collection $skupina) => (float) $skupina->sum(fn (Transaction $t) => abs((float) $t->amount_from)));

        $zkratky = [1 => 'led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];

        return collect(range(0, 11))
            ->map(function (int $i) use ($od, $soucty, $zkratky) {
                $mesic = $od->addMonths($i);

                return [
                    $zkratky[$mesic->month].' '.$mesic->format('y'),
                    (int) round((float) ($soucty[$mesic->format('Y-m')] ?? 0)),
                ];
            })
            ->all();
    }

    /** @return array{income: int, spent: int, saved: int} */
    private function rok(GallerySpace $prostor, Budget $rozpocet): array
    {
        $od = CarbonImmutable::today()->startOfYear();

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('occurred_at', '>=', $od)
            ->get(['type', 'amount_from', 'amount_to']);

        $prijem = (float) $pohyby->where('type', 'income')->sum(fn (Transaction $t) => abs((float) ($t->amount_to ?? $t->amount_from)));
        $vydaj = (float) $pohyby->where('type', '!=', 'income')->sum(fn (Transaction $t) => abs((float) $t->amount_from));

        return [
            'income' => (int) round($prijem),
            'spent' => (int) round($vydaj),
            'saved' => (int) round($prijem - $vydaj),
        ];
    }

    /**
     * Cíle spoření: `[název, cíl, ušetřeno, měsíčně, termín, poznámka, odkaz]`
     *
     * @return list<array<int, mixed>>
     */
    private function cile(Budget $rozpocet): array
    {
        return $rozpocet->goals()->get()->map(function ($cil) {
            $zbyva = max(0.0, (float) $cil->target_amount - (float) $cil->saved_amount);
            $mesicu = $cil->target_on
                ? max(1, CarbonImmutable::today()->diffInMonths(CarbonImmutable::parse($cil->target_on), false))
                : null;

            return [
                $cil->name,
                (int) round((float) $cil->target_amount),
                (int) round((float) $cil->saved_amount),
                $mesicu ? (int) round($zbyva / $mesicu) : 0,
                $cil->target_on ? 'do '.CarbonImmutable::parse($cil->target_on)->format('n/Y') : 'průběžně',
                (string) ($cil->note ?? ''),
                null,
            ];
        })->values()->all();
    }

    /**
     * Kolik na měsíc.
     *
     * Rozpočet nemusí mít měsíční příjem: Makinčin rozpočet na Německo ho nemá,
     * má jednorázově složené prostředky na půl roku. Prototyp v hlavičce čeká
     * měsíční číslo, takže se prostředky rozpočítají na měsíce, které rozpočet
     * pokrývá. Nula by tam znamenala „nemáme z čeho žít", což není totéž jako
     * „příjem nechodí měsíčně".
     */
    private function mesicniPrijem(Budget $rozpocet): float
    {
        if ($rozpocet->monthly_income !== null) {
            return (float) $rozpocet->monthly_income;
        }

        return (float) ($rozpocet->starting_funds ?? 0) / max(1, $rozpocet->monthsCovered());
    }

    /**
     * Limity kategorií rozpočtu.
     *
     * `budget_category_limits` nemá vlastní model — v aplikaci se do ní sahá
     * dotazovačem, a tenhle poskytovatel to dělá stejně, aby nevznikla druhá
     * cesta k témuž řádku.
     *
     * @return Collection<int, object>
     */
    private function limity(Budget $rozpocet): Collection
    {
        return collect(\Illuminate\Support\Facades\DB::table('budget_category_limits as l')
            ->leftJoin('finance_categories as k', 'k.id', '=', 'l.finance_category_id')
            ->where('l.budget_id', $rozpocet->id)
            // Vzestupně, jako je aplikace financuje: nejdřív to, co se neshazuje.
            ->orderBy('l.priority')
            ->orderByDesc('l.amount')
            ->get(['l.id', 'l.finance_category_id', 'l.amount', 'l.priority', 'k.name as nazev', 'k.icon as ikona']));
    }

    /** @return array<int, float> */
    private function utracenoPoKategoriich(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do): array
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('excluded_from_budget', false)
            ->whereBetween('occurred_at', [$od, $do])
            ->selectRaw('category_id, SUM(ABS(amount_from)) AS castka')
            ->groupBy('category_id')
            ->pluck('castka', 'category_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function poznamkaKategorie(float $plan, float $utraceno, string $mena): string
    {
        if ($plan <= 0) {
            return 'bez limitu';
        }

        $zbyva = $plan - $utraceno;

        return $zbyva >= 0
            ? 'zbývá '.$this->castka($zbyva, $mena)
            : 'přečerpáno o '.$this->castka(abs($zbyva), $mena);
    }

    /**
     * Ikony aplikace jsou z Lucide (`bus`), prototyp kreslí Phosphor (`ph-bus`).
     *
     * Většina jmen se kryje, takže stačí předpona; co se nekryje, má převod níž.
     * Bez toho by u každé kategorie zůstalo prázdné místo po ikoně.
     */
    private function ikona(?string $jmeno): string
    {
        if (! $jmeno) {
            return 'ph-tag';
        }

        $prevod = [
            'shopping-cart' => 'basket', 'utensils' => 'fork-knife', 'fuel' => 'gas-pump',
            'circle-parking' => 'car', 'spray-can' => 'spray-bottle', 'ticket' => 'ticket',
            'home' => 'house-line', 'heart-pulse' => 'heartbeat', 'shirt' => 't-shirt',
            'graduation-cap' => 'student', 'plane' => 'airplane-tilt', 'train-front' => 'train',
            'piggy-bank' => 'piggy-bank', 'wallet' => 'wallet', 'gift' => 'gift',
        ];

        return 'ph-'.($prevod[$jmeno] ?? $jmeno);
    }

    // ——— účty ———

    /** @return Collection<int, Wallet> */
    private function penezenky(GallerySpace $prostor): Collection
    {
        return Wallet::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  Collection<int, Wallet>  $penezenky
     * @return list<array<int, mixed>>
     */
    private function ucty(GallerySpace $prostor, Collection $penezenky): array
    {
        if ($penezenky->isEmpty()) {
            return [];
        }

        // `walletBalances` vrací pole, ne modely, a klíčem je uuid.
        $zustatky = $this->kniha->walletBalances($prostor)->keyBy('uuid');
        $zustatek = fn (Wallet $p) => (float) ($zustatky[$p->uuid]['balance'] ?? $p->opening_balance ?? 0);
        $celkem = max(1.0, (float) $penezenky->sum(fn (Wallet $p) => abs($zustatek($p))));

        return $penezenky->map(function (Wallet $p) use ($zustatek, $celkem) {
            $castka = $zustatek($p);

            return [
                $p->name,
                trim(($p->kindLabel() ?: 'účet').($p->iban ? ' · '.$p->iban : '')),
                (int) round($castka),
                $this->ikonaUctu($p->kind),
                // Napojení na banku je zvláštní modul; bez něj je účet ruční.
                $p->kind === 'bank' ? 'napojeno' : 'ručně',
                $p->updated_at ? 'upraveno '.$p->updated_at->diffForHumans() : 'bez pohybu',
                $p->sort_order === 0 ? 'hlavní' : null,
                (int) round(abs($castka) / $celkem * 100),
            ];
        })->values()->all();
    }

    private function ikonaUctu(?string $druh): string
    {
        return match ($druh) {
            'bank' => 'ph-bank',
            'cash' => 'ph-money',
            'savings' => 'ph-piggy-bank',
            default => 'ph-credit-card',
        };
    }

    // ——— pevné náklady ———

    /** @return list<array<string, mixed>> */
    private function pevneNaklady(?Budget $rozpocet): array
    {
        if ($rozpocet === null) {
            return [];
        }

        $mesicu = max(1, $rozpocet->monthsCovered());

        // „Nedotknutelné" kategorie jsou to, co dvojice platí každý měsíc bez
        // rozmýšlení — přesně to, co prototyp v `SHARED` ukazuje.
        return $this->limity($rozpocet)
            ->filter(fn (object $limit) => (int) ($limit->priority ?? 100) <= self::PEVNE)
            ->map(fn (object $limit) => [
                'id' => 'p'.$limit->id,
                'name' => $limit->nazev ?? 'Nezařazeno',
                'amount' => (int) round((float) $limit->amount / $mesicu),
            ])
            ->values()
            ->all();
    }

    // ——— formát ———

    private function den(?\DateTimeInterface $kdy): string
    {
        return $kdy ? CarbonImmutable::parse($kdy)->format('j. n.') : '—';
    }

    private function mesic(CarbonImmutable $kdy): string
    {
        $jmena = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
            'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

        return $jmena[$kdy->month].' '.$kdy->year;
    }

    /**
     * Částka i s měnou rozpočtu.
     *
     * Makinčin rozpočet na Německo je v eurech; napsat u něj „zbývá 60 Kč" by byla
     * přesně ta tichá nepravda, kvůli které se pak dělají rozhodnutí naslepo.
     */
    private function castka(float $castka, string $mena): string
    {
        return number_format($castka, 0, ',', ' ').' '.$this->znakMeny($mena);
    }

    private function znakMeny(string $mena): string
    {
        return match (strtoupper($mena)) {
            'CZK' => 'Kč',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $mena,
        };
    }
}
