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
use App\Services\Finance\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Zápis, oprava a smazání záznamu.
 *
 * Kontroly jsou tady, ne v modelu: jsou to pravidla o tom, co smí přijít zvenčí.
 * Zápis a oprava jdou jednou cestou — kdyby měla oprava vlastní kontroly, dala by se
 * jí do knihy dostat kombinace, kterou zápis nepustí, a rozešly by se.
 *
 * Rozlišuje se **chyba** od **varování**. Chyba je stav, který nemůže být pravda:
 * převod mezi různými měnami, nulová částka, rozdělení, které nedá celek. Varování je
 * něco neobvyklého, co ale pravda být může — dva stejné nákupy za den nebo kurz mimo
 * obvyklý rozsah. Varování se nesmí tvářit jako chyba, protože pak se lidé naučí
 * odklikávat všechno včetně skutečných chyb.
 */
class FinanceEntryController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function store(Request $request): JsonResponse
    {
        $space = $this->space($request);
        [$atributy, $podily, $varovani] = $this->priprav($request, $space);

        if ($varovani !== [] && ! $request->boolean('potvrzeno')) {
            // 409, ne 422: data jsou v pořádku, jen neobvyklá. Formulář na to musí
            // odpovědět otázkou, ne červeným polem.
            return response()->json(['needs_confirmation' => true, 'warnings' => $varovani], 409);
        }

        $t = Transaction::create($atributy + [
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
        ]);

        $this->ulozPodily($t, $podily, $space);

        return response()->json(['uuid' => $t->uuid, 'warnings' => $varovani], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $t = Transaction::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        [$atributy, $podily, $varovani] = $this->priprav($request, $space, $t);

        if ($varovani !== [] && ! $request->boolean('potvrzeno')) {
            return response()->json(['needs_confirmation' => true, 'warnings' => $varovani], 409);
        }

        $t->update($atributy);
        $this->ulozPodily($t, $podily, $space);

        return response()->json(['uuid' => $t->uuid]);
    }

    /**
     * Smazání i s vysvětlením dopadu.
     *
     * U směny a převodu se ruší dva pohyby najednou, takže se před potvrzením řekne,
     * co to udělá se zůstatky — jinak by se člověk dozvěděl až z rozvahy, že mu na
     * eurovém účtu chybí dva tisíce.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $t = Transaction::where('gallery_space_id', $space->id)->where('uuid', $uuid)
            ->with(['walletFrom', 'walletTo'])->firstOrFail();

        $dopad = $this->dopadSmazani($t);

        if (! $request->boolean('potvrzeno')) {
            return response()->json(['needs_confirmation' => true, 'impact' => $dopad], 409);
        }

        TransactionShare::where('transaction_id', $t->id)->delete();
        $t->delete();

        return response()->json(['deleted' => true, 'impact' => $dopad]);
    }

    /**
     * Zkontroluje vstup a přeloží ho na sloupce.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    private function priprav(Request $request, GallerySpace $space, ?Transaction $puvodni = null): array
    {
        $data = $request->validate([
            'type' => 'required|in:income,expense,transfer,exchange,withdrawal,deposit',
            'occurred_at' => 'required|date',
            'wallet_from' => 'nullable|uuid',
            'wallet_to' => 'nullable|uuid',
            'amount_from' => 'nullable|numeric',
            'amount_to' => 'nullable|numeric',
            'category' => 'nullable|uuid',
            'trip' => 'nullable|uuid',
            'payer_partner_id' => 'nullable|integer',
            'beneficiary_partner_id' => 'nullable|integer',
            'fee_amount' => 'nullable|numeric|min:0',
            'fee_currency' => 'nullable|string|size:3',
            'fee_included' => 'sometimes|boolean',
            'reference_rate' => 'nullable|numeric|min:0',
            'provider' => 'nullable|string|max:60',
            'counterparty' => 'nullable|string|max:200',
            'place' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:500',
            'excluded_from_budget' => 'sometimes|boolean',
            'exclusion_reason' => 'nullable|string|max:200',
            'refund_of' => 'nullable|uuid',
            'is_settlement' => 'sometimes|boolean',
            'split' => 'nullable|array|max:10',
            'split.*.partner_id' => 'required|integer',
            'split.*.amount' => 'required|numeric|min:0',
            'split.*.basis' => 'nullable|in:equal,percent,fixed',
        ]);

        $penezenky = Wallet::where('gallery_space_id', $space->id)->get()->keyBy('uuid');
        $z = ! empty($data['wallet_from']) ? $penezenky->get($data['wallet_from']) : null;
        $do = ! empty($data['wallet_to']) ? $penezenky->get($data['wallet_to']) : null;

        $this->zkontroluj($data, $z, $do);

        $castkaZ = (float) ($data['amount_from'] ?? $data['amount_to'] ?? 0);
        $castkaDo = (float) ($data['amount_to'] ?? $data['amount_from'] ?? 0);

        $kategorie = ! empty($data['category'])
            ? FinanceCategory::where('gallery_space_id', $space->id)->where('uuid', $data['category'])->first()
            : null;

        $cesta = ! empty($data['trip'])
            ? FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $data['trip'])->first()
            : null;

        $refundace = ! empty($data['refund_of'])
            ? Transaction::where('gallery_space_id', $space->id)->where('uuid', $data['refund_of'])->first()
            : null;

        $poplatek = (float) ($data['fee_amount'] ?? 0);

        $atributy = [
            'type' => $data['type'],
            'occurred_at' => $data['occurred_at'],
            'wallet_from_id' => $z?->id,
            'wallet_to_id' => $do?->id,
            'amount_from' => $z ? $castkaZ : null,
            'currency_from' => $z?->currency,
            'amount_to' => $do ? $castkaDo : null,
            'currency_to' => $do?->currency,
            'rate' => $data['type'] === 'exchange' && $castkaDo > 0 ? $castkaZ / $castkaDo : null,
            'reference_rate' => $data['reference_rate'] ?? null,
            'fee_amount' => $poplatek,
            'fee_currency' => $poplatek > 0
                ? strtoupper($data['fee_currency'] ?? $z?->currency ?? 'CZK')
                : null,
            'fee_included' => $poplatek > 0 ? (bool) ($data['fee_included'] ?? false) : false,
            'category_id' => $kategorie?->id,
            'finance_project_id' => $cesta?->id,
            'payer_partner_id' => $data['payer_partner_id'] ?? null,
            'beneficiary_partner_id' => $data['beneficiary_partner_id'] ?? null,
            'provider' => $data['provider'] ?? null,
            'counterparty' => $data['counterparty'] ?? null,
            'place' => $data['place'] ?? null,
            'description' => $data['description'] ?? null,
            'excluded_from_budget' => (bool) ($data['excluded_from_budget'] ?? false),
            'exclusion_reason' => $data['exclusion_reason'] ?? null,
            'refund_of_id' => $refundace?->id,
            'is_settlement' => (bool) ($data['is_settlement'] ?? false),
            'state' => 'approved',
        ];

        $podily = array_map(
            fn (array $p) => $p + ['currency' => $z?->currency ?? $do?->currency],
            $data['split'] ?? [],
        );

        return [$atributy, $podily, $this->varovani($space, $atributy, $z, $do, $puvodni)];
    }

    /**
     * Stavy, které nemůžou být pravda.
     *
     * Každá hláška říká, co je špatně a co s tím — „Něco se pokazilo" je odpověď,
     * po které člověk zkusí totéž znovu.
     */
    private function zkontroluj(array $data, ?Wallet $z, ?Wallet $do): void
    {
        $chyby = [];

        $potrebaZ = in_array($data['type'], ['expense', 'transfer', 'exchange', 'withdrawal', 'deposit'], true);
        $potrebaDo = in_array($data['type'], ['income', 'transfer', 'exchange', 'withdrawal', 'deposit'], true);

        if ($potrebaZ && ! $z) $chyby['wallet_from'] = 'Vyberte účet, ze kterého peníze odešly.';
        if ($potrebaDo && ! $do) $chyby['wallet_to'] = 'Vyberte účet, na který peníze přišly.';

        $castka = (float) ($data['amount_from'] ?? $data['amount_to'] ?? 0);

        if ($castka <= 0) {
            $chyby['amount_from'] = 'Částka musí být větší než nula.';
        }

        if ($z && $do) {
            if ($z->id === $do->id) {
                $chyby['wallet_to'] = 'Zdrojový a cílový účet nemůže být stejný.';
            } elseif (in_array($data['type'], ['transfer', 'withdrawal', 'deposit'], true) && $z->currency !== $do->currency) {
                $chyby['wallet_to'] = "Převod je mezi účty ve stejné měně. {$z->currency} na {$do->currency} je směna — přepněte typ na Směnu, aby šel zapsat kurz.";
            } elseif ($data['type'] === 'exchange' && $z->currency === $do->currency) {
                $chyby['wallet_to'] = 'Směna je mezi různými měnami. Přesun ve stejné měně je Převod.';
            }
        }

        if ($data['type'] === 'exchange' && empty($data['amount_to'])) {
            $chyby['amount_to'] = 'Zadejte, kolik doopravdy přišlo — z toho se počítá skutečný kurz.';
        }

        if (($data['excluded_from_budget'] ?? false) && empty($data['exclusion_reason'])) {
            $chyby['exclusion_reason'] = 'Napište důvod. Bez něj se za půl roku nedá zjistit, proč se ta částka nikde nepočítá.';
        }

        // Rozdělení musí dát celek. Rozdíl do haléře je zaokrouhlení, víc je chyba.
        if (! empty($data['split'])) {
            $soucet = array_sum(array_column($data['split'], 'amount'));

            if (abs($soucet - $castka) > 0.011) {
                $chyby['split'] = 'Rozdělení mezi Adriho a Maki musí dát dohromady celou částku. Zatím dává '
                    .number_format($soucet, 2, ',', ' ').' z '.number_format($castka, 2, ',', ' ').'.';
            }
        }

        if ($chyby !== []) {
            throw ValidationException::withMessages($chyby);
        }
    }

    /**
     * Neobvyklé, ale možné. Ptá se, neblokuje.
     *
     * @return array<int, array<string, mixed>>
     */
    private function varovani(GallerySpace $space, array $a, ?Wallet $z, ?Wallet $do, ?Transaction $puvodni): array
    {
        $v = [];

        // Kurz mimo rozumný rozsah. Nejčastější příčina je posunutá desetinná čárka
        // nebo prohozený směr — obojí se pozná až podle výsledku, ne podle vstupu.
        if ($a['type'] === 'exchange' && $a['amount_from'] > 0 && $a['amount_to'] > 0) {
            $kurz = $a['currency_to'] === 'EUR'
                ? $a['amount_from'] / $a['amount_to']
                : $a['amount_to'] / $a['amount_from'];

            if ($kurz < 15 || $kurz > 40) {
                $v[] = [
                    'key' => 'kurz',
                    'title' => 'Neobvyklý kurz',
                    'body' => 'Vychází '.number_format($kurz, 2, ',', ' ').' Kč za euro. Sedí to? Bývá to posunutá desetinná čárka nebo prohozený směr směny.',
                ];
            }
        }

        // Nedostatek peněz na účtu. Může být pravda — zápisy se dodělávají zpětně.
        if ($z && $a['type'] !== 'income') {
            $zustatek = collect($this->finance->balances($space)['wallets'])->firstWhere('uuid', $z->uuid);
            $odchod = (float) $a['amount_from'] + ($a['fee_included'] ? 0 : (float) $a['fee_amount']);

            if ($zustatek && $zustatek['balance'] < $odchod) {
                $v[] = [
                    'key' => 'zustatek',
                    'title' => 'Na účtu tolik není',
                    'body' => "Na {$z->name} je podle knihy ".number_format($zustatek['balance'], 2, ',', ' ')." {$z->currency}. Buď chybí dřívější zápis, nebo šel účet do mínusu.",
                ];
            }
        }

        // Možná duplicita: stejná částka, účet a den.
        if ($a['type'] === 'expense' && $z) {
            $stejne = Transaction::where('gallery_space_id', $space->id)
                ->where('type', 'expense')
                ->where('wallet_from_id', $z->id)
                ->where('amount_from', $a['amount_from'])
                ->whereDate('occurred_at', $a['occurred_at'])
                ->when($puvodni, fn ($q) => $q->where('id', '!=', $puvodni->id))
                ->count();

            if ($stejne > 0) {
                $v[] = [
                    'key' => 'duplicita',
                    'title' => 'Podobný výdaj už dnes je',
                    'body' => 'Stejná částka ze stejného účtu dnes už zapsaná je. Dvakrát nakoupit se dá — jen ať to není omylem dvakrát totéž.',
                ];
            }
        }

        // Částka výrazně mimo obvyklý rozsah kategorie.
        if ($a['type'] === 'expense' && $a['category_id']) {
            $obvykle = Transaction::where('gallery_space_id', $space->id)
                ->where('category_id', $a['category_id'])
                ->where('type', 'expense')
                ->where('currency_from', $a['currency_from'])
                ->when($puvodni, fn ($q) => $q->where('id', '!=', $puvodni->id))
                ->pluck('amount_from')->map(fn ($c) => (float) $c);

            if ($obvykle->count() >= 5) {
                $prumer = $obvykle->avg();

                if ($prumer > 0 && (float) $a['amount_from'] > $prumer * 8) {
                    $v[] = [
                        'key' => 'castka',
                        'title' => 'Nezvykle vysoká částka',
                        'body' => 'V téhle kategorii bývá kolem '.number_format($prumer, 2, ',', ' ')." {$a['currency_from']}. Nesedí desetinná čárka nebo měna?",
                    ];
                }
            }
        }

        return $v;
    }

    private function dopadSmazani(Transaction $t): array
    {
        $zmeny = [];

        if ($t->walletFrom) {
            $zmeny[] = ['wallet' => $t->walletFrom->name, 'change' => '+'.number_format((float) $t->amount_from, 2, ',', ' ').' '.$t->currency_from];
        }

        if ($t->walletTo) {
            $zmeny[] = ['wallet' => $t->walletTo->name, 'change' => '−'.number_format((float) $t->amount_to, 2, ',', ' ').' '.$t->currency_to];
        }

        return [
            'type' => $t->type,
            'wallets' => $zmeny,
            'note' => in_array($t->type, ['exchange', 'transfer', 'withdrawal', 'deposit'], true)
                ? 'Zruší se obě strany najednou — peníze se vrátí tam, odkud odešly.'
                : null,
        ];
    }

    private function ulozPodily(Transaction $t, array $podily, GallerySpace $space): void
    {
        // Napřed smazat: při opravě chodí celý seznam, takže přidávat k tomu, co už
        // tam je, by po druhém uložení dělilo dvojnásobek.
        TransactionShare::where('transaction_id', $t->id)->delete();

        foreach ($podily as $p) {
            if (! Partner::where('gallery_space_id', $space->id)->whereKey($p['partner_id'])->exists()) {
                continue;
            }

            TransactionShare::create([
                'transaction_id' => $t->id,
                'partner_id' => (int) $p['partner_id'],
                'amount' => $p['amount'],
                'currency' => $p['currency'],
                'basis' => $p['basis'] ?? null,
            ]);
        }
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }
}
