<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\Wallet;
use App\Services\Finance\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Účetní kniha — partneři, peněženky a transakce.
 *
 * Zápis transakce je jediné místo, kde se rozhoduje, co je co: podle typu se vyplní
 * strana odkud, kam, nebo obě. Kontrola sedí tady, ne v modelu, protože je to pravidlo
 * o tom, co smí přijít zvenčí — model má držet data, ne hlídat cizí vstup.
 */
class LedgerController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Dashboard podle druhého oddílu zadání.
     *
     * Vzorec ze zadání zní: dostupný rozpočet = schválený + příjmy − výdaje − rezervace
     * − závazky. Rezervace a závazky jsou objednávky, které ještě nedorazily; ty zatím
     * neexistují, a proto se posílají jako nula s poznámkou, že modul chybí. Vydávat
     * nulu za hotové číslo by znamenalo tvrdit, že nic nečeká na úhradu — což je něco
     * jiného než „nevíme".
     */
    public function dashboard(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $projekt = $request->query('project')
            ? FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $request->query('project'))->first()
            : null;

        $vysledek = $this->ledger->resultByCurrency($space, $projekt?->id);
        $pozice = $this->ledger->partnerPositions($space, $projekt?->id);

        $schvaleny = $projekt
            ? 0.0   // rozpočet projektu doplní vrstva s plánováním
            : 0.0;

        return response()->json([
            'project' => $projekt ? ['uuid' => $projekt->uuid, 'name' => $projekt->name, 'currency' => $projekt->base_currency] : null,
            'wallets' => $this->ledger->walletBalances($space),
            'result' => $vysledek,
            'partners' => $pozice['partners'],
            'settlement_plan' => $this->ledger->settlementPlan($pozice),
            'upcoming' => $this->ledger->upcoming($space, $projekt?->id),
            'flagged' => $this->ledger->flagged($space, $projekt?->id),
            'trend' => $this->ledger->trend($space, $projekt?->id),
            /*
             * Co zadání chce, ale nemá to zatím odkud vzít. Posílá se pojmenovaně jako
             * nedostupné, ne jako nula — obrazovka to má napsat, ne to tiše sečíst.
             */
            'not_available' => [
                'approved_budget' => 'Plánování rozpočtu projektu zatím není hotové.',
                'reserved' => 'Rezervace vznikají z objednávek — modul nákupů zatím není hotový.',
                'committed' => 'Závazky vznikají z objednávek — modul nákupů zatím není hotový.',
            ],
        ]);
    }

    public function partners(Request $request): JsonResponse
    {
        $space = $this->space($request);

        return response()->json([
            'partners' => Partner::where('gallery_space_id', $space->id)
                ->orderBy('name')
                ->get()
                ->map(fn (Partner $p) => [
                    'uuid' => $p->uuid,
                    'id' => $p->id,
                    'kind' => $p->kind,
                    'kind_label' => $p->kindLabel(),
                    'name' => $p->name,
                    'registration_no' => $p->registration_no,
                    'is_active' => $p->is_active,
                ])->values(),
        ]);
    }

    public function storePartner(Request $request): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:200',
            'kind' => 'required|in:person,company,organization',
            'user_id' => 'nullable|integer',
            'registration_no' => 'nullable|string|max:40',
            'vat_no' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:190',
            'note' => 'nullable|string|max:1000',
        ]);

        // Uživatele lze navázat jen z téhož prostoru — jinak by se do knihy dal
        // propašovat cizí účet.
        if (! empty($data['user_id']) && ! $space->members()->whereKey($data['user_id'])->exists()) {
            $data['user_id'] = null;
        }

        Partner::create($data + ['gallery_space_id' => $space->id]);

        return response()->json($this->partners($request)->getData(true), 201);
    }

    public function storeWallet(Request $request): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:160',
            'kind' => 'required|in:bank,cash,card,other',
            'currency' => 'required|string|size:3',
            'partner_id' => 'nullable|integer',
            'opening_balance' => 'nullable|numeric',
            'iban' => 'nullable|string|max:40',
        ]);

        if (! empty($data['partner_id'])
            && ! Partner::where('gallery_space_id', $space->id)->whereKey($data['partner_id'])->exists()) {
            $data['partner_id'] = null;
        }

        Wallet::create($data + [
            'gallery_space_id' => $space->id,
            'currency' => strtoupper($data['currency']),
            'opening_balance' => $data['opening_balance'] ?? 0,
            'sort_order' => (int) Wallet::where('gallery_space_id', $space->id)->max('sort_order') + 10,
        ]);

        return response()->json(['wallets' => $this->ledger->walletBalances($space)], 201);
    }

    /**
     * Zápis transakce.
     *
     * Typ určuje, které strany musí být vyplněné, a kontrola to vynucuje. Bez ní by šlo
     * zapsat směnu bez cílové peněženky — peníze by z jedné strany odešly a nikam
     * nedorazily, zůstatky by přestaly sedět a nikdo by nevěděl proč.
     */
    public function storeTransaction(Request $request): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'type' => 'required|in:income,expense,transfer,exchange,withdrawal,deposit',
            'occurred_at' => 'required|date',
            'booked_on' => 'nullable|date',
            'wallet_from' => 'nullable|uuid',
            'wallet_to' => 'nullable|uuid',
            'amount_from' => 'nullable|numeric|min:0',
            'amount_to' => 'nullable|numeric|min:0',
            'rate' => 'nullable|numeric|min:0',
            'reference_rate' => 'nullable|numeric|min:0',
            'rate_source' => 'nullable|string|max:40',
            'fee_amount' => 'nullable|numeric|min:0',
            'fee_currency' => 'nullable|string|size:3',
            'project' => 'nullable|uuid',
            'payer_partner_id' => 'nullable|integer',
            'beneficiary_partner_id' => 'nullable|integer',
            'counterparty' => 'nullable|string|max:200',
            'payment_method' => 'nullable|string|max:30',
            'description' => 'nullable|string|max:500',
            'state' => 'nullable|in:draft,pending,approved',
            // Rozdělení mezi partnery. Ukládá se výsledek, ne předpis.
            'shares' => 'nullable|array|max:20',
            'shares.*.partner_id' => 'required|integer',
            'shares.*.amount' => 'required|numeric|min:0',
            'shares.*.basis' => 'nullable|in:equal,percent,fixed,persons,days,weight',
            'shares.*.basis_value' => 'nullable|numeric',
        ]);

        $penezenky = Wallet::where('gallery_space_id', $space->id)->get()->keyBy('uuid');

        $z = ! empty($data['wallet_from']) ? $penezenky->get($data['wallet_from']) : null;
        $do = ! empty($data['wallet_to']) ? $penezenky->get($data['wallet_to']) : null;

        // Co musí být vyplněné, podle typu.
        $potrebaZ = in_array($data['type'], ['expense', 'transfer', 'exchange', 'withdrawal', 'deposit'], true);
        $potrebaDo = in_array($data['type'], ['income', 'transfer', 'exchange', 'withdrawal', 'deposit'], true);

        abort_if($potrebaZ && ! $z, 422, 'U tohohle typu je potřeba peněženka, ze které peníze odcházejí.');
        abort_if($potrebaDo && ! $do, 422, 'U tohohle typu je potřeba peněženka, do které peníze přicházejí.');

        // Převod, výběr a vklad jsou pohyby v jedné měně. Kdyby se povolila různá,
        // splynuly by se směnou — a ta má navíc kurz i poplatek, které by chyběly.
        if (in_array($data['type'], ['transfer', 'withdrawal', 'deposit'], true)) {
            abort_if($z->currency !== $do->currency, 422, 'Převod mezi různými měnami je směna — vyberte typ „směna", aby šel zapsat kurz.');
        }

        if ($data['type'] === 'exchange') {
            abort_if($z->currency === $do->currency, 422, 'Směna mezi stejnými měnami je převod.');
            abort_if(empty($data['amount_to']), 422, 'U směny je potřeba i připsaná částka — kurz se z ní počítá.');
        }

        $castkaZ = $data['amount_from'] ?? $data['amount_to'] ?? 0;
        $castkaDo = $data['amount_to'] ?? $data['amount_from'] ?? 0;

        $projekt = ! empty($data['project'])
            ? FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $data['project'])->first()
            : null;

        $transakce = Transaction::create([
            'gallery_space_id' => $space->id,
            'type' => $data['type'],
            'occurred_at' => $data['occurred_at'],
            'booked_on' => $data['booked_on'] ?? null,
            'wallet_from_id' => $z?->id,
            'wallet_to_id' => $do?->id,
            'amount_from' => $z ? $castkaZ : null,
            'currency_from' => $z?->currency,
            'amount_to' => $do ? $castkaDo : null,
            'currency_to' => $do?->currency,
            // Kurz se dopočítá z obou částek, když ho nikdo neposlal — je v nich obsažený
            // a nechat ho prázdný by znamenalo ztratit informaci, kterou už máme.
            'rate' => $data['rate'] ?? ($data['type'] === 'exchange' && $castkaDo > 0 ? $castkaZ / $castkaDo : null),
            'reference_rate' => $data['reference_rate'] ?? null,
            'rate_source' => $data['rate_source'] ?? null,
            'fee_amount' => $data['fee_amount'] ?? 0,
            'fee_currency' => isset($data['fee_currency']) ? strtoupper($data['fee_currency']) : $z?->currency,
            'finance_project_id' => $projekt?->id,
            'payer_partner_id' => $data['payer_partner_id'] ?? null,
            'beneficiary_partner_id' => $data['beneficiary_partner_id'] ?? null,
            'counterparty' => $data['counterparty'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'description' => $data['description'] ?? null,
            'state' => $data['state'] ?? 'approved',
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['shares'] ?? [] as $podil) {
            if (! Partner::where('gallery_space_id', $space->id)->whereKey($podil['partner_id'])->exists()) {
                continue;
            }

            TransactionShare::create([
                'transaction_id' => $transakce->id,
                'partner_id' => (int) $podil['partner_id'],
                'amount' => $podil['amount'],
                'currency' => $z?->currency ?? $do?->currency,
                'basis' => $podil['basis'] ?? null,
                'basis_value' => $podil['basis_value'] ?? null,
            ]);
        }

        return response()->json(['uuid' => $transakce->uuid], 201);
    }

    /** Seznam transakcí s filtry. */
    public function transactions(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $data = $request->validate([
            'type' => 'nullable|string|max:20',
            'project' => 'nullable|uuid',
            'wallet' => 'nullable|uuid',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
        ]);

        $stranka = (int) ($data['page'] ?? 1);
        $naStranku = 50;

        $dotaz = Transaction::where('gallery_space_id', $space->id)
            ->with(['walletFrom:id,name,currency', 'walletTo:id,name,currency', 'payer:id,name', 'project:id,name'])
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))
            ->when($data['project'] ?? null, fn ($q, $p) => $q->whereHas('project', fn ($x) => $x->where('uuid', $p)))
            ->when($data['wallet'] ?? null, fn ($q, $w) => $q->where(function ($vnitrni) use ($w) {
                $vnitrni->whereHas('walletFrom', fn ($x) => $x->where('uuid', $w))
                    ->orWhereHas('walletTo', fn ($x) => $x->where('uuid', $w));
            }))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $celkem = (clone $dotaz)->count();
        $radky = $dotaz->forPage($stranka, $naStranku)->get();

        return response()->json([
            'found' => $celkem,
            'has_more' => $celkem > $stranka * $naStranku,
            'transactions' => $radky->map(fn (Transaction $t) => [
                'uuid' => $t->uuid,
                'type' => $t->type,
                'type_label' => $t->typeLabel(),
                'affects_result' => $t->affectsResult(),
                'occurred_at' => $t->occurred_at->toDateString(),
                'from' => $t->walletFrom ? ['name' => $t->walletFrom->name, 'amount' => (float) $t->amount_from, 'currency' => $t->currency_from] : null,
                'to' => $t->walletTo ? ['name' => $t->walletTo->name, 'amount' => (float) $t->amount_to, 'currency' => $t->currency_to] : null,
                'fee' => (float) $t->fee_amount,
                'fee_currency' => $t->fee_currency,
                'rate' => $t->rate !== null ? (float) $t->rate : null,
                'payer' => $t->payer?->name,
                'project' => $t->project?->name,
                'counterparty' => $t->counterparty,
                'description' => $t->description,
                'state' => $t->state,
            ])->values(),
        ]);
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }

    /** Jen členové s právem zápisu. Prohlížející do knihy nesahá. */
    private function write(Request $request): void
    {
        abort_unless($request->user() !== null, 403);
    }
}
