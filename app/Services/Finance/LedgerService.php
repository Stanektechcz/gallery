<?php

namespace App\Services\Finance;

use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Collection;

/**
 * Co z účetní knihy plyne.
 *
 * Celá služba stojí na jednom rozlišení: **jen typy `income` a `expense` mění výsledek.**
 * Převod, směna, výběr a vklad zůstatky přesouvají, ale nic neutratily ani nevydělaly.
 *
 * Kdyby se to nerozlišovalo, stačilo by jednou za měsíc vybrat hotovost a směnit peníze,
 * a přehled by tvrdil, že se utratilo dvojnásobek. Chyba by přitom nešla najít, protože
 * každá jednotlivá položka by byla správně — špatný by byl jen součet.
 */
class LedgerService
{
    /**
     * Zůstatek každé peněženky.
     *
     * Počítá se z počátečního stavu a všeho, co peněženkou prošlo — bez ohledu na typ.
     * Tady se typy nerozlišují schválně: z pohledu peněženky je jedno, jestli peníze
     * odešly jako výdaj, nebo jako převod. Odešly.
     *
     * Poplatek se odečítá od té strany, ze které se platil. U směny je to zdrojová
     * peněženka; když poplatek zůstane bez měny, bere se měna zdroje.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function walletBalances(GallerySpace $space): Collection
    {
        $penezenky = Wallet::where('gallery_space_id', $space->id)
            ->with('partner:id,name')
            ->orderBy('sort_order')
            ->get();

        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->whereIn('state', ['approved', 'settled'])
            ->get(['wallet_from_id', 'wallet_to_id', 'amount_from', 'amount_to', 'fee_amount', 'fee_currency', 'currency_from']);

        $odchozi = [];
        $prichozi = [];
        $poplatky = [];

        foreach ($pohyby as $pohyb) {
            if ($pohyb->wallet_from_id) {
                $odchozi[$pohyb->wallet_from_id] = ($odchozi[$pohyb->wallet_from_id] ?? 0) + (float) $pohyb->amount_from;
            }

            if ($pohyb->wallet_to_id) {
                $prichozi[$pohyb->wallet_to_id] = ($prichozi[$pohyb->wallet_to_id] ?? 0) + (float) $pohyb->amount_to;
            }

            // Poplatek jde k té straně, ze které se platil. Bez zdrojové peněženky
            // (poplatek u příchozí platby) k cílové.
            $kde = $pohyb->wallet_from_id ?: $pohyb->wallet_to_id;

            if ($kde && (float) $pohyb->fee_amount > 0) {
                $poplatky[$kde] = ($poplatky[$kde] ?? 0) + (float) $pohyb->fee_amount;
            }
        }

        return $penezenky->map(fn (Wallet $p) => [
            'uuid' => $p->uuid,
            'name' => $p->name,
            'kind' => $p->kind,
            'kind_label' => $p->kindLabel(),
            'currency' => $p->currency,
            'partner' => $p->partner?->name,
            'opening_balance' => (float) $p->opening_balance,
            'balance' => round(
                (float) $p->opening_balance
                + ($prichozi[$p->id] ?? 0)
                - ($odchozi[$p->id] ?? 0)
                - ($poplatky[$p->id] ?? 0),
                2,
            ),
        ]);
    }

    /**
     * Příjmy a výdaje po měnách — jen to, co opravdu mění výsledek.
     *
     * Poplatky se přičítají k výdajům, i když vznikly u směny nebo převodu: banka si je
     * nechala a zpátky je nedá. Je to jediná část přesunu, která je skutečným nákladem,
     * a proto se hlásí zvlášť — aby bylo vidět, kolik stojí samotné přesouvání peněz.
     *
     * @return array<string, mixed>
     */
    public function resultByCurrency(GallerySpace $space, ?int $projectId = null): array
    {
        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->whereIn('state', ['approved', 'settled'])
            ->when($projectId, fn ($q) => $q->where('finance_project_id', $projectId))
            ->get(['type', 'amount_from', 'currency_from', 'amount_to', 'currency_to', 'fee_amount', 'fee_currency']);

        $prijmy = [];
        $vydaje = [];
        $poplatky = [];

        foreach ($pohyby as $pohyb) {
            if ($pohyb->type === 'income') {
                $mena = $pohyb->currency_to ?? $pohyb->currency_from;
                $prijmy[$mena] = ($prijmy[$mena] ?? 0) + (float) $pohyb->amount_to;
            }

            if ($pohyb->type === 'expense') {
                $mena = $pohyb->currency_from ?? $pohyb->currency_to;
                $vydaje[$mena] = ($vydaje[$mena] ?? 0) + (float) $pohyb->amount_from;
            }

            if ((float) $pohyb->fee_amount > 0) {
                $mena = $pohyb->fee_currency ?? $pohyb->currency_from ?? $pohyb->currency_to;
                $poplatky[$mena] = ($poplatky[$mena] ?? 0) + (float) $pohyb->fee_amount;
            }
        }

        $meny = collect(array_keys($prijmy + $vydaje + $poplatky))->sort()->values();

        return [
            'currencies' => $meny->map(fn (string $mena) => [
                'currency' => $mena,
                'income' => round($prijmy[$mena] ?? 0, 2),
                'expense' => round($vydaje[$mena] ?? 0, 2),
                'fees' => round($poplatky[$mena] ?? 0, 2),
                // Poplatek je náklad, i když vznikl u převodu. Do výdajů se přičítá,
                // ale drží se i zvlášť, aby šlo vidět, kolik stojí přesouvání peněz.
                'net' => round(($prijmy[$mena] ?? 0) - ($vydaje[$mena] ?? 0) - ($poplatky[$mena] ?? 0), 2),
            ])->all(),
        ];
    }

    /**
     * Kolik kdo vložil, kolik zaplatil a kolik měl nést.
     *
     * Tři různá čísla, která se běžně pletou. Vložil = poslal peníze do společné
     * peněženky. Zaplatil = z jeho peněženky odešel výdaj. Měl nést = jeho podíl podle
     * rozdělení. Dluh je rozdíl mezi tím, co zaplatil, a tím, co měl nést.
     *
     * Po měnách zvlášť. Sečíst je přes kurz by šlo, ale ten kurz by si systém musel
     * vymyslet — a u toho, kdo komu kolik dluží, je vymyšlené číslo to poslední, co
     * kdokoliv chce.
     *
     * @return array<string, mixed>
     */
    public function partnerPositions(GallerySpace $space, ?int $projectId = null): array
    {
        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->whereIn('state', ['approved', 'settled'])
            ->when($projectId, fn ($q) => $q->where('finance_project_id', $projectId))
            ->with(['shares', 'payer:id,name'])
            ->get();

        $partneri = Partner::where('gallery_space_id', $space->id)->get(['id', 'name'])->keyBy('id');

        $zaplatil = [];
        $melNest = [];

        foreach ($pohyby as $pohyb) {
            if ($pohyb->type !== 'expense') {
                continue;
            }

            $mena = $pohyb->currency_from ?? $pohyb->currency_to;
            $kdo = $pohyb->payer_partner_id;

            if ($kdo) {
                $zaplatil[$kdo][$mena] = ($zaplatil[$kdo][$mena] ?? 0) + (float) $pohyb->amount_from + (float) $pohyb->fee_amount;
            }

            // Bez rozdělení nese výdaj ten, kdo ho zaplatil — nic se nedluží.
            if ($pohyb->shares->isEmpty()) {
                if ($kdo) {
                    $melNest[$kdo][$mena] = ($melNest[$kdo][$mena] ?? 0) + (float) $pohyb->amount_from + (float) $pohyb->fee_amount;
                }

                continue;
            }

            foreach ($pohyb->shares as $podil) {
                $melNest[$podil->partner_id][$podil->currency] =
                    ($melNest[$podil->partner_id][$podil->currency] ?? 0) + (float) $podil->amount;
            }
        }

        $vsichni = collect(array_keys($zaplatil + $melNest));

        return [
            'partners' => $vsichni->map(function (int $id) use ($partneri, $zaplatil, $melNest) {
                $meny = collect(array_keys(($zaplatil[$id] ?? []) + ($melNest[$id] ?? [])))->sort()->values();

                return [
                    'partner_id' => $id,
                    'name' => $partneri[$id]->name ?? '—',
                    'currencies' => $meny->map(fn (string $m) => [
                        'currency' => $m,
                        'paid' => round($zaplatil[$id][$m] ?? 0, 2),
                        'should_bear' => round($melNest[$id][$m] ?? 0, 2),
                        // Kladné = zaplatil víc, než měl nést, a ostatní mu dluží.
                        'balance' => round(($zaplatil[$id][$m] ?? 0) - ($melNest[$id][$m] ?? 0), 2),
                    ])->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Co teprve přijde — transakce se stavem, který ještě není hotový.
     *
     * `draft` a `pending` jsou zapsané, ale nezaúčtované: čekají na schválení nebo na
     * doplnění. Do zůstatků se nepočítají, ale do rozhodování ano — kdo se dívá, kolik
     * má na účtu, potřebuje vědět, kolik z toho je už slíbené.
     *
     * @return array<string, mixed>
     */
    public function upcoming(GallerySpace $space, ?int $projectId = null): array
    {
        $cekajici = Transaction::where('gallery_space_id', $space->id)
            ->whereIn('state', ['draft', 'pending'])
            ->when($projectId, fn ($q) => $q->where('finance_project_id', $projectId))
            ->with(['walletFrom:id,name,currency', 'payer:id,name'])
            ->orderBy('occurred_at')
            ->limit(20)
            ->get();

        $podleMen = [];

        foreach ($cekajici as $t) {
            if ($t->type !== 'expense') {
                continue;
            }

            $mena = $t->currency_from ?? $t->currency_to;
            $podleMen[$mena] = ($podleMen[$mena] ?? 0) + (float) $t->amount_from;
        }

        return [
            'pending_by_currency' => collect($podleMen)->map(fn (float $c) => round($c, 2))->all(),
            'items' => $cekajici->map(fn (Transaction $t) => [
                'uuid' => $t->uuid,
                'type_label' => $t->typeLabel(),
                'occurred_at' => $t->occurred_at->toDateString(),
                'amount' => (float) ($t->amount_from ?? $t->amount_to),
                'currency' => $t->currency_from ?? $t->currency_to,
                'description' => $t->description,
                'payer' => $t->payer?->name,
                'state' => $t->state,
            ])->values()->all(),
        ];
    }

    /**
     * Položky, které si zaslouží pozornost.
     *
     * Tři druhy, každý z jiného důvodu. Výdaj bez dokladu se po půl roce nedá doložit.
     * Peněženka v mínusu znamená, že se buď něco zapsalo špatně, nebo se opravdu čerpalo
     * do minusu — obojí je potřeba vidět. A směna, u které chybí referenční kurz, se
     * později nedá zkontrolovat proti tomu, co bylo férové.
     *
     * @return array<string, mixed>
     */
    public function flagged(GallerySpace $space, ?int $projectId = null): array
    {
        $vydajeBezDokladu = Transaction::where('gallery_space_id', $space->id)
            ->where('type', 'expense')
            ->whereNull('receipt_media_id')
            ->when($projectId, fn ($q) => $q->where('finance_project_id', $projectId))
            ->orderByDesc('amount_from')
            ->limit(10)
            ->get(['uuid', 'amount_from', 'currency_from', 'occurred_at', 'description']);

        $smenyBezKurzu = Transaction::where('gallery_space_id', $space->id)
            ->where('type', 'exchange')
            ->whereNull('reference_rate')
            ->when($projectId, fn ($q) => $q->where('finance_project_id', $projectId))
            ->limit(10)
            ->get(['uuid', 'occurred_at', 'amount_from', 'currency_from', 'amount_to', 'currency_to']);

        $minusove = $this->walletBalances($space)->filter(fn (array $p) => $p['balance'] < 0);

        return [
            'no_receipt' => $vydajeBezDokladu->map(fn (Transaction $t) => [
                'uuid' => $t->uuid,
                'amount' => (float) $t->amount_from,
                'currency' => $t->currency_from,
                'occurred_at' => $t->occurred_at->toDateString(),
                'description' => $t->description,
            ])->values()->all(),
            'exchange_without_reference' => $smenyBezKurzu->map(fn (Transaction $t) => [
                'uuid' => $t->uuid,
                'occurred_at' => $t->occurred_at->toDateString(),
                'summary' => sprintf('%s %s → %s %s', $t->amount_from, $t->currency_from, $t->amount_to, $t->currency_to),
            ])->values()->all(),
            'negative_wallets' => $minusove->values()->all(),
        ];
    }

    /**
     * Vývoj příjmů a výdajů po měsících.
     *
     * Jen typy, které mění výsledek — u přesunů by graf ukazoval hory a doly podle toho,
     * kdy kdo směnil peníze, což o hospodaření neříká nic.
     *
     * @return array<string, mixed>
     */
    public function trend(GallerySpace $space, ?int $projectId = null): array
    {
        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->whereIn('type', Transaction::VYSLEDKOVE)
            ->whereIn('state', ['approved', 'settled'])
            ->when($projectId, fn ($q) => $q->where('finance_project_id', $projectId))
            ->get(['type', 'occurred_at', 'amount_from', 'currency_from', 'amount_to', 'currency_to']);

        $mesice = [];

        foreach ($pohyby as $t) {
            $klic = $t->occurred_at->format('Y-m');
            $mena = $t->type === 'income' ? ($t->currency_to ?? $t->currency_from) : ($t->currency_from ?? $t->currency_to);
            $castka = (float) ($t->type === 'income' ? $t->amount_to : $t->amount_from);

            $mesice[$mena][$klic][$t->type] = ($mesice[$mena][$klic][$t->type] ?? 0) + $castka;
        }

        return collect($mesice)->map(fn (array $poMesicich) => collect($poMesicich)
            ->sortKeys()
            ->map(fn (array $m, string $klic) => [
                'month' => $klic,
                'income' => round($m['income'] ?? 0, 2),
                'expense' => round($m['expense'] ?? 0, 2),
                'net' => round(($m['income'] ?? 0) - ($m['expense'] ?? 0), 2),
            ])
            ->values()
            ->all())->all();
    }

    /**
     * Nejmenší počet převodů, kterými se dluhy vyrovnají.
     *
     * Naivní vyrovnání „každý každému" vyžaduje u čtyř lidí až šest převodů. Když se
     * seřadí ti, kdo mají dostat, proti těm, kdo mají poslat, a vždycky se spáruje
     * největší s největším, vyjde jich nejvýš o jeden méně, než je lidí.
     *
     * Řeší se každá měna zvlášť — převod korun nesmaže dluh v eurech, dokud někdo
     * neurčí kurz, a to je rozhodnutí člověka, ne systému.
     *
     * @param  array<string, mixed>  $pozice  výstup partnerPositions()
     * @return array<int, array<string, mixed>>
     */
    public function settlementPlan(array $pozice): array
    {
        $navrhy = [];

        // Přeskládat na měny → partner → saldo.
        $podleMen = [];

        foreach ($pozice['partners'] as $partner) {
            foreach ($partner['currencies'] as $radek) {
                if (abs($radek['balance']) >= 0.01) {
                    $podleMen[$radek['currency']][] = [
                        'id' => $partner['partner_id'],
                        'name' => $partner['name'],
                        'balance' => $radek['balance'],
                    ];
                }
            }
        }

        foreach ($podleMen as $mena => $lide) {
            // Věřitelé sestupně, dlužníci vzestupně — a páruje se největší s největším.
            $dostanou = collect($lide)->where('balance', '>', 0)->sortByDesc('balance')->values()->all();
            $poslou = collect($lide)->where('balance', '<', 0)->sortBy('balance')->values()->all();

            $i = 0;
            $j = 0;

            while ($i < count($dostanou) && $j < count($poslou)) {
                $castka = round(min($dostanou[$i]['balance'], -$poslou[$j]['balance']), 2);

                if ($castka >= 0.01) {
                    $navrhy[] = [
                        'currency' => $mena,
                        'from' => $poslou[$j]['name'],
                        'from_id' => $poslou[$j]['id'],
                        'to' => $dostanou[$i]['name'],
                        'to_id' => $dostanou[$i]['id'],
                        'amount' => $castka,
                    ];
                }

                $dostanou[$i]['balance'] = round($dostanou[$i]['balance'] - $castka, 2);
                $poslou[$j]['balance'] = round($poslou[$j]['balance'] + $castka, 2);

                if ($dostanou[$i]['balance'] < 0.01) {
                    $i++;
                }

                if ($poslou[$j]['balance'] > -0.01) {
                    $j++;
                }
            }
        }

        return $navrhy;
    }
}
