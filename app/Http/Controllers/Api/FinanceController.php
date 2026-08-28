<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\Wallet;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Modul Rozpočet — společné finance na cestách.
 *
 * Kniha je jediný zdroj pravdy; přehledy, rozpočty i statistiky jsou pohledy na ni.
 * Filtr období se počítá ve `FinanceFilter`, aby všechny obrazovky mluvily o témž.
 */
class FinanceController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    /**
     * Číselníky pro formuláře — jedním dotazem.
     *
     * Formulář výdaje potřebuje kategorie, účty, partnery, cesty i šablony. Čtyři
     * dotazy by znamenaly, že se u pokladny čeká na čtyři odpovědi a rychlý zápis
     * přestane být rychlý.
     */
    public function lookups(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $this->finance->prepare($space);

        $cesta = $this->finance->activeTrip($space);
        $zustatky = $this->finance->balances($space);

        return response()->json([
            'categories' => FinanceCategory::where('gallery_space_id', $space->id)
                ->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (FinanceCategory $k) => [
                    'uuid' => $k->uuid, 'id' => $k->id, 'name' => $k->name, 'kind' => $k->kind,
                    'icon' => $k->icon, 'color' => $k->color, 'is_favourite' => $k->is_favourite,
                    'default_wallet_id' => $k->default_wallet_id,
                    'default_split' => $k->default_split,
                ])->values(),
            'wallets' => $zustatky['wallets'],
            'balances' => $zustatky['by_currency'],
            'partners' => Partner::where('gallery_space_id', $space->id)->where('is_active', true)
                ->orderBy('name')->get(['id', 'uuid', 'name', 'kind']),
            'trips' => FinanceProject::where('gallery_space_id', $space->id)->where('kind', 'trip')
                ->orderByDesc('starts_on')->get()
                ->map(fn (FinanceProject $c) => $this->cestaRadek($c))->values(),
            'active_trip' => $cesta ? $this->cestaRadek($cesta) : null,
            // Poslední volby. Předvyplnění šetří u pokladny tři klepnutí a spec ho
            // vyžaduje — ale musí jít jedním klepnutím změnit, proto je to jen návrh.
            'last_used' => $this->posledniVolby($space),
        ]);
    }

    /**
     * Přehled — čtyři otázky, na které má odpovědět do několika sekund.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $this->finance->prepare($space);

        $filtr = FinanceFilter::zDotazu($request->all(), $space);
        $pohyby = $filtr->dotaz($space)->get();

        $zustatky = $this->finance->balances($space);
        $partneri = Partner::where('gallery_space_id', $space->id)->where('is_active', true)->get();

        $souhrn = $this->finance->summary($pohyby);
        $hlavniMena = $this->hlavniMena($souhrn, $filtr);

        // Předchozí srovnatelné období — jen pro trend, ne pro součty.
        $minule = $filtr->predchozi();
        $souhrnMinule = $minule ? $this->finance->summary($minule->dotaz($space)->get()) : [];

        $rozpocet = $this->rozpocetStavu($space, $filtr, $pohyby, $hlavniMena);

        return response()->json([
            'filter' => [
                'period' => $filtr->obdobi,
                'label' => $filtr->popis,
                'from' => $filtr->od->toDateString(),
                'to' => $filtr->do?->toDateString(),
                'days' => $filtr->dni(),
                'trip' => $filtr->cesta ? $this->cestaRadek($filtr->cesta) : null,
                'chips' => $filtr->stitky(),
            ],
            'summary' => $souhrn,
            'previous' => $souhrnMinule,
            'main_currency' => $hlavniMena,
            'budget' => $rozpocet,
            'balances' => $zustatky['by_currency'],
            'wallets' => $zustatky['wallets'],
            'daily' => $filtr->do ? $this->finance->daily($pohyby, $hlavniMena, $filtr->od, $filtr->do) : [],
            'categories' => $this->finance->byCategory($pohyby, $hlavniMena),
            'partner_balance' => $this->finance->partnerBalance($pohyby, $partneri),
            'exchange' => $this->smenyPrehled($space, $pohyby),
            'recent' => $this->radky($filtr->dotaz($space)->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get()),
            'alerts' => $this->upozorneni($space, $pohyby, $rozpocet, $zustatky),
            'active_trip' => ($c = $this->finance->activeTrip($space)) ? $this->cestaRadek($c) : null,
        ]);
    }

    /**
     * Směny — historie, skutečné výsledky a co ty směny stály.
     *
     * KPI se počítají z celé historie, ne z období: „kolik eur držíme" a „za kolik jsme
     * je pořídili" jsou stavové údaje, které filtr na měsíc nedává smysl zužovat.
     * Objem a poplatky naopak patří do zvoleného období — tam otázka zní „kolik nás to
     * stálo tenhle měsíc".
     */
    public function exchanges(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $filtr = FinanceFilter::zDotazu($request->all(), $space);

        $vsechny = Transaction::where('gallery_space_id', $space->id)
            ->where('type', 'exchange')
            ->with(['walletFrom:id,name,currency', 'walletTo:id,name,currency', 'project:id,name'])
            ->orderBy('occurred_at')->orderBy('id')
            ->get();

        $vObdobi = $vsechny->filter(fn (Transaction $t) => $t->occurred_at->betweenIncluded(
            $filtr->od, $filtr->do ?? Carbon::today()->addYears(50),
        ));

        $radky = $vsechny->map(function (Transaction $t) {
            $k = $this->finance->exchangeRate($t);

            return [
                'uuid' => $t->uuid,
                'occurred_at' => $t->occurred_at->toDateString(),
                'provider' => $t->provider,
                'from' => ['name' => $t->walletFrom?->name, 'amount' => (float) $t->amount_from, 'currency' => $t->currency_from],
                'to' => ['name' => $t->walletTo?->name, 'amount' => (float) $t->amount_to, 'currency' => $t->currency_to],
                'trip' => $t->project?->name,
                'rate' => $k,
            ];
        })->values();

        return response()->json([
            'acquisition' => $this->finance->eurAcquisition($space),
            'period' => ['label' => $filtr->popis, 'from' => $filtr->od->toDateString(), 'to' => $filtr->do?->toDateString()],
            'period_volume' => $vObdobi->groupBy('currency_from')
                ->map(fn (Collection $s, string $m) => ['currency' => $m, 'amount' => round((float) $s->sum('amount_from'), 2), 'count' => $s->count()])
                ->values(),
            'period_fees' => $vObdobi->groupBy(fn (Transaction $t) => $t->fee_currency ?? $t->currency_from)
                ->map(fn (Collection $s, string $m) => ['currency' => $m, 'amount' => round($s->sum(fn (Transaction $t) => $t->feePaidExtra()), 2)])
                ->filter(fn (array $r) => $r['amount'] > 0)->values(),
            'providers' => $this->poskytovatele($vsechny),
            'exchanges' => $radky->reverse()->values(),
            'count' => $vsechny->count(),
        ]);
    }

    /**
     * Porovnání poskytovatelů podle skutečného výsledku, ne podle nabízeného kurzu.
     *
     * Měří se, kolik cílové měny doopravdy přišlo po odečtení poplatků. Poskytovatel
     * s lepším kurzem a vyšším poplatkem vyjde hůř než ten, kdo si nechá rozdíl
     * v kurzu — a z reklamního čísla to poznat nejde.
     *
     * Nejlepší se označí jen tehdy, když má aspoň dvě směny. Z jediné směny závěr
     * neplyne: mohla padnout na výjimečně dobrý den a nesouvisí s poskytovatelem.
     *
     * @param  Collection<int, Transaction>  $smeny
     * @return array<int, array<string, mixed>>
     */
    private function poskytovatele(Collection $smeny): array
    {
        $radky = $smeny
            ->filter(fn (Transaction $t) => $t->currency_to === 'EUR')
            ->groupBy(fn (Transaction $t) => $t->provider ?: 'Neuvedeno')
            ->map(function (Collection $s, string $jmeno) {
                $kurzy = $s->map(fn (Transaction $t) => $this->finance->exchangeRate($t))->filter();

                if ($kurzy->isEmpty()) return null;

                // Vážený průměr, ne prostý: jedna stokorunová směna nemá vážit stejně
                // jako padesátitisícová.
                $koruny = $kurzy->sum(fn (array $k) => $k['received'] * $k['effective']);
                $eura = $kurzy->sum('received');

                return [
                    'name' => $jmeno,
                    'count' => $s->count(),
                    'volume' => round($kurzy->sum('spent'), 2),
                    'volume_currency' => $s->first()->currency_from,
                    'received' => round($eura, 2),
                    'average_rate' => $eura > 0 ? round($koruny / $eura, 4) : null,
                    'fees' => round($kurzy->sum('fee'), 2),
                    'eur_per_1000_czk' => $eura > 0 ? round(1000 / ($koruny / $eura), 2) : null,
                    'comparable' => $s->count() >= 2,
                ];
            })
            ->filter()
            ->sortBy('average_rate')
            ->values();

        // Nejlepší a nejhorší jen mezi porovnatelnými — a jen když jsou aspoň dva.
        $porovnatelne = $radky->where('comparable', true);

        return $radky->map(fn (array $r) => $r + [
            'is_best' => $porovnatelne->count() >= 2 && $r['comparable'] && $r['name'] === $porovnatelne->first()['name'],
            'is_worst' => $porovnatelne->count() >= 2 && $r['comparable'] && $r['name'] === $porovnatelne->last()['name'],
        ])->all();
    }

    /** Seznam transakcí — hlavní místo dohledání. */
    public function transactions(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $filtr = FinanceFilter::zDotazu($request->all(), $space);

        $dotaz = $filtr->dotaz($space)->orderByDesc('occurred_at')->orderByDesc('id');
        $celkem = (clone $dotaz)->count();
        $stranka = max(1, (int) $request->input('strana', 1));

        $radky = $dotaz->forPage($stranka, 60)->get();

        return response()->json([
            'found' => $celkem,
            'has_more' => $celkem > $stranka * 60,
            'summary' => $this->finance->summary($filtr->dotaz($space)->get()),
            'filter' => ['label' => $filtr->popis, 'chips' => $filtr->stitky()],
            'transactions' => $this->radky($radky),
        ]);
    }

    /**
     * Jeden řádek seznamu.
     *
     * Směna i převod se ukazují jako **jeden** záznam s oběma stranami. Dva řádky
     * („odešlo z CZK", „přišlo na EUR") by vypadaly jako dvě různé věci a součet
     * transakcí by se zdvojnásobil.
     *
     * @param  Collection<int, Transaction>  $pohyby
     */
    private function radky(Collection $pohyby): array
    {
        return $pohyby->map(function (Transaction $t) {
            $kurz = $this->finance->exchangeRate($t);

            return [
                'uuid' => $t->uuid,
                'type' => $t->type,
                'type_label' => $this->nazevTypu($t),
                'counts_to_budget' => $t->countsTowardsBudget(),
                'occurred_at' => $t->occurred_at->toDateString(),
                'from' => $t->walletFrom ? [
                    'uuid' => $t->walletFrom->uuid ?? null, 'name' => $t->walletFrom->name,
                    'amount' => (float) $t->amount_from, 'currency' => $t->currency_from,
                ] : null,
                'to' => $t->walletTo ? [
                    'uuid' => $t->walletTo->uuid ?? null, 'name' => $t->walletTo->name,
                    'amount' => (float) $t->amount_to, 'currency' => $t->currency_to,
                ] : null,
                'category' => $t->category ? ['name' => $t->category->name, 'color' => $t->category->color, 'icon' => $t->category->icon] : null,
                'payer' => $t->payer?->name,
                'trip' => $t->project?->name,
                'counterparty' => $t->counterparty,
                'provider' => $t->provider,
                'place' => $t->place,
                'description' => $t->description,
                'fee' => (float) $t->fee_amount,
                'fee_currency' => $t->fee_currency,
                'fee_included' => (bool) $t->fee_included,
                'rate' => $kurz,
                'is_settlement' => (bool) $t->is_settlement,
                'is_refund' => $t->refund_of_id !== null,
                'excluded' => (bool) $t->excluded_from_budget,
                'exclusion_reason' => $t->exclusion_reason,
                'has_split' => $t->shares->isNotEmpty(),
                'state' => $t->state,
                'updated_at' => $t->updated_at?->toDateTimeString(),
            ];
        })->values()->all();
    }

    /**
     * Stav rozpočtu pro zvolené období.
     *
     * Přednost má rozpočet cesty: kdo se dívá na pobyt, chce vidět jeho limit, ne
     * měsíční. Když žádný rozpočet není, vrací se null a obrazovka místo prázdného
     * ukazatele nabídne, ať se založí.
     */
    private function rozpocetStavu(GallerySpace $space, FinanceFilter $filtr, Collection $pohyby, string $mena): ?array
    {
        // Když se jede, má cestovní rozpočet přednost i bez přepnutí období. Kdo je
        // v Německu, ptá se „kolik zbývá do konce pobytu", ne „kolik do konce měsíce"
        // — a musel by na to klepnout, aby to uviděl.
        $cesta = $filtr->cesta ?? $this->finance->activeTrip($space);

        // Když přehled ukazuje měsíc a rozpočet patří cestě, počítá se čerpání z celé
        // cesty. Jinak by proti limitu na celý pobyt stály útraty za pár dní a zbývalo
        // by pořád skoro všechno.
        if ($cesta && $filtr->cesta === null) {
            $pohyby = Transaction::where('gallery_space_id', $space->id)
                ->with(['walletFrom:id,name,currency,partner_id,kind', 'category:id,name,color,icon', 'refundOf:id,category_id'])
                ->where('finance_project_id', $cesta->id)
                ->get();
        }

        $limit = $cesta?->budget_amount !== null ? (float) $cesta->budget_amount : null;
        $rezerva = $cesta?->reserve_amount !== null ? (float) $cesta->reserve_amount : 0.0;
        $menaLimitu = $cesta?->base_currency ?? $mena;

        if ($limit === null) {
            return null;
        }

        $utraceno = $pohyby
            ->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $menaLimitu)
            ->sum('amount_from');

        // Poplatky jsou skutečný náklad a do čerpání patří.
        $utraceno += $pohyby->sum(fn (Transaction $t) => ($t->fee_currency ?? $t->currency_from) === $menaLimitu ? $t->feePaidExtra() : 0);

        // Vrácené peníze snižují čerpání — jinak by reklamovaný nákup rozpočet zatížil
        // navždycky, přestože se peníze vrátily.
        $vraceno = $pohyby
            ->filter(fn (Transaction $t) => $t->type === 'income' && $t->refund_of_id !== null && $t->currency_to === $menaLimitu)
            ->sum('amount_to');

        $ciste = max(0, (float) $utraceno - (float) $vraceno);

        $bezpecne = $this->finance->safeDaily(
            $limit, $ciste, $rezerva,
            $cesta?->starts_on ?? $filtr->od,
            $cesta?->ends_on ?? $filtr->do,
        );

        return [
            'name' => $cesta?->name,
            'kind' => $cesta ? 'trip' : 'monthly',
            'currency' => $menaLimitu,
            'limit' => round($limit, 2),
            'spent' => round($ciste, 2),
            'refunded' => round((float) $vraceno, 2),
            'remaining' => round($limit - $ciste, 2),
            'reserve' => round($rezerva, 2),
            'percent' => $limit > 0 ? min(999, (int) round($ciste / $limit * 100)) : 0,
            'safe_daily' => $bezpecne,
            'state' => $ciste > $limit ? 'over' : ($ciste >= $limit * 0.8 ? 'near' : 'ok'),
        ];
    }

    /** Souhrn směn pro Přehled. */
    private function smenyPrehled(GallerySpace $space, Collection $pohyby): array
    {
        $porizeni = $this->finance->eurAcquisition($space);

        $smeny = $pohyby->where('type', 'exchange');
        $posledni = $smeny->sortByDesc('occurred_at')->first();

        return [
            'acquisition' => $porizeni,
            'last' => $posledni ? [
                'occurred_at' => $posledni->occurred_at->toDateString(),
                'provider' => $posledni->provider,
                'rate' => $this->finance->exchangeRate($posledni),
            ] : null,
            'period_volume' => $smeny->groupBy('currency_from')
                ->map(fn (Collection $s, string $m) => ['currency' => $m, 'amount' => round((float) $s->sum('amount_from'), 2)])
                ->values()->all(),
            'period_fees' => $smeny->groupBy(fn (Transaction $t) => $t->fee_currency ?? $t->currency_from)
                ->map(fn (Collection $s, string $m) => ['currency' => $m, 'amount' => round($s->sum(fn (Transaction $t) => $t->feePaidExtra()), 2)])
                ->filter(fn (array $r) => $r['amount'] > 0)
                ->values()->all(),
            'count' => $smeny->count(),
        ];
    }

    /**
     * Kontextová upozornění.
     *
     * Jen to, co je právě teď pravda, a ke každému konkrétní akce. Upozornění, které
     * visí pořád, se po týdnu přestane číst — a pak nezabere ani tehdy, kdy má.
     */
    private function upozorneni(GallerySpace $space, Collection $pohyby, ?array $rozpocet, array $zustatky): array
    {
        $u = [];

        if ($rozpocet && $rozpocet['percent'] >= 80) {
            $u[] = [
                'key' => 'rozpocet-'.$rozpocet['percent'],
                'tone' => $rozpocet['state'] === 'over' ? 'danger' : 'warn',
                'title' => $rozpocet['state'] === 'over'
                    ? 'Rozpočet je vyčerpaný'
                    : "Z rozpočtu zbývá {$rozpocet['percent']} % vyčerpáno",
                'body' => $rozpocet['state'] === 'over'
                    ? "Přesáhli jsme o ".number_format($rozpocet['safe_daily']['over_by'] ?? 0, 2, ',', ' ')." {$rozpocet['currency']}."
                    : "Utraceno {$rozpocet['percent']} % z limitu.",
                'action' => ['label' => 'Zobrazit rozpočet', 'tab' => 'rozpocty'],
            ];
        }

        foreach ($zustatky['wallets'] as $p) {
            if ($p['balance'] < 0) {
                $u[] = [
                    'key' => 'zustatek-'.$p['uuid'],
                    'tone' => 'danger',
                    'title' => "Účet {$p['name']} je v mínusu",
                    'body' => 'Zůstatek podle zapsaných pohybů je záporný — nejspíš chybí zápis příjmu nebo směny.',
                    'action' => ['label' => 'Otevřít účty', 'tab' => 'ucty'],
                ];
            }
        }

        // Možná duplicita: stejná částka, účet a den. Varuje, neblokuje.
        $duplicity = $pohyby->where('type', 'expense')
            ->groupBy(fn (Transaction $t) => $t->occurred_at->toDateString().'|'.$t->wallet_from_id.'|'.(string) $t->amount_from)
            ->filter(fn (Collection $s) => $s->count() > 1);

        foreach ($duplicity as $skupina) {
            $t = $skupina->first();
            $u[] = [
                'key' => 'duplicita-'.$t->uuid,
                'tone' => 'warn',
                'title' => 'Dva stejné výdaje ve stejný den',
                'body' => number_format((float) $t->amount_from, 2, ',', ' ')." {$t->currency_from} ze stejného účtu {$skupina->count()}×. Může to být v pořádku — dvakrát nakoupit se dá.",
                'action' => ['label' => 'Zobrazit je', 'tab' => 'transakce', 'filter' => ['od' => $t->occurred_at->toDateString(), 'do' => $t->occurred_at->toDateString()]],
            ];
        }

        return $u;
    }

    private function cestaRadek(FinanceProject $c): array
    {
        return [
            'uuid' => $c->uuid,
            'name' => $c->name,
            'country' => $c->country,
            'city' => $c->city,
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'days_left' => $c->dniDoKonce(),
            'budget' => $c->budget_amount !== null ? (float) $c->budget_amount : null,
            'reserve' => $c->reserve_amount !== null ? (float) $c->reserve_amount : null,
            'currency' => $c->base_currency,
            'default_wallet_id' => $c->default_wallet_id,
            'is_active' => (bool) $c->is_active,
            'state' => $c->state,
        ];
    }

    /** Naposledy použité volby — návrh pro formulář, ne pravidlo. */
    private function posledniVolby(GallerySpace $space): array
    {
        $posledni = Transaction::where('gallery_space_id', $space->id)
            ->where('type', 'expense')->orderByDesc('id')->first();

        return [
            'wallet_id' => $posledni?->wallet_from_id,
            'payer_partner_id' => $posledni?->payer_partner_id,
            'category_id' => $posledni?->category_id,
        ];
    }

    /**
     * Měna, ve které se přehled ukazuje.
     *
     * Ta, ve které se v tomhle období nejvíc utratilo — na cestě do Německa eura,
     * doma koruny. Bez toho by se přehled musel ptát hned na začátku, nebo by natvrdo
     * ukazoval koruny i uprostřed pobytu, kde nejsou.
     */
    private function hlavniMena(array $souhrn, FinanceFilter $filtr): string
    {
        if ($filtr->cesta?->base_currency) {
            return $filtr->cesta->base_currency;
        }

        return $souhrn[0]['currency'] ?? 'CZK';
    }

    private function nazevTypu(Transaction $t): string
    {
        if ($t->is_settlement) return 'Vyrovnání';
        if ($t->refund_of_id !== null) return 'Vrácené peníze';

        return match ($t->type) {
            'income' => 'Příjem',
            'expense' => 'Výdaj',
            'transfer' => 'Převod',
            'exchange' => 'Směna',
            'withdrawal' => 'Výběr hotovosti',
            'deposit' => 'Vklad hotovosti',
            default => $t->type,
        };
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }
}
