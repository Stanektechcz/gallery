<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Finance\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Správa účtů, cest, kategorií a partnerů.
 *
 * Všechno tady sdílí jedno pravidlo: **historie se nepřepisuje.** Účet, na který
 * ukazují transakce, nejde smazat; kategorie taky ne. Když se něco přestane používat,
 * odloží se — zmizí z nabídek a zůstane u starých záznamů, protože ty se staly.
 *
 * Zůstatek účtu se taky nepřepisuje ručně. Vzniká z pohybů a opravit ho jde jen
 * zapsanou korekcí s důvodem. Kdyby šel přepsat, přestal by být odvoditelný a nikdo
 * by nezjistil, kde se rozešel se skutečností.
 */
class FinanceSetupController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    // ---------------------------------------------------------------- účty

    public function storeWallet(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:160',
            'kind' => 'required|in:bank,card,cash,other',
            'currency' => 'required|string|size:3',
            'partner_id' => 'nullable|integer',
            'opening_balance' => 'nullable|numeric',
        ]);

        if (! empty($data['partner_id'])
            && ! Partner::where('gallery_space_id', $space->id)->whereKey($data['partner_id'])->exists()) {
            $data['partner_id'] = null;
        }

        // Velká písmena se musí přepsat v `$data`, ne přidat vedle. Operátor `+` u polí
        // nechává přednost levé straně, takže by se uložilo to, co přišlo z formuláře —
        // a „eur" vedle „EUR" by pak byly dvě různé měny, které se nikdy nesečtou.
        $data['currency'] = strtoupper($data['currency']);

        Wallet::create($data + [
            'gallery_space_id' => $space->id,
            'opening_balance' => $data['opening_balance'] ?? 0,
            'is_active' => true,
            'sort_order' => (int) Wallet::where('gallery_space_id', $space->id)->max('sort_order') + 10,
        ]);

        return response()->json($this->finance->balances($space), 201);
    }

    /**
     * Oprava účtu.
     *
     * Měna se měnit nedá. Transakce si u sebe drží měnu, ve které se staly, a přepnutí
     * účtu z korun na eura by je nepřepočítalo — jen by k týmž číslům přidalo jinou
     * značku. Kdo se splete v měně, založí nový účet.
     *
     * Počáteční zůstatek se měnit dá jen dokud je účet prázdný. Potom už je to údaj,
     * ze kterého se počítají všechny následující zůstatky, a tiše ho posunout znamená
     * posunout celou historii.
     */
    public function updateWallet(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $ucet = Wallet::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => 'sometimes|string|max:160',
            'kind' => 'sometimes|in:bank,card,cash,other',
            'partner_id' => 'nullable|integer',
            'opening_balance' => 'sometimes|numeric',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        if (array_key_exists('opening_balance', $data) && $this->pouzitiUctu($space, $ucet) > 0) {
            $soucasny = (float) $ucet->opening_balance;

            if (abs((float) $data['opening_balance'] - $soucasny) > 0.005) {
                abort(409, 'Účet už má zapsané pohyby, takže počáteční zůstatek posunout nejde — posunul by celou historii. Rozdíl zapište jako korekci.');
            }

            unset($data['opening_balance']);
        }

        if (! empty($data['partner_id'])
            && ! Partner::where('gallery_space_id', $space->id)->whereKey($data['partner_id'])->exists()) {
            unset($data['partner_id']);
        }

        $ucet->update($data);

        return response()->json($this->finance->balances($space));
    }

    /**
     * Korekce zůstatku.
     *
     * Když skutečný stav na účtu nesedí s knihou, nevzniká oprava přepsáním čísla, ale
     * zapsaným rozdílem s důvodem. Za půl roku pak jde zjistit, že se tehdy něco
     * nezapsalo — místo aby zůstatek prostě někdy někde skočil.
     */
    public function correctWallet(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $ucet = Wallet::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'actual_balance' => 'required|numeric',
            'reason' => 'required|string|max:200',
            'occurred_at' => 'nullable|date',
        ]);

        $podleKnihy = (float) collect($this->finance->balances($space)['wallets'])
            ->firstWhere('uuid', $ucet->uuid)['balance'];

        $rozdil = round((float) $data['actual_balance'] - $podleKnihy, 2);

        if (abs($rozdil) < 0.005) {
            return response()->json(['message' => 'Zůstatek už sedí, korekce není potřeba.', 'difference' => 0]);
        }

        // Chybí peníze → výdaj; přebývají → příjem. Obojí označené jako korekce, aby
        // šlo ve statistikách odlišit od skutečného nákupu.
        Transaction::create([
            'gallery_space_id' => $space->id,
            'type' => $rozdil < 0 ? 'expense' : 'income',
            'occurred_at' => $data['occurred_at'] ?? Carbon::today()->toDateString(),
            'wallet_from_id' => $rozdil < 0 ? $ucet->id : null,
            'wallet_to_id' => $rozdil > 0 ? $ucet->id : null,
            'amount_from' => $rozdil < 0 ? abs($rozdil) : null,
            'currency_from' => $ucet->currency,
            'amount_to' => $rozdil > 0 ? $rozdil : null,
            'currency_to' => $rozdil > 0 ? $ucet->currency : null,
            'description' => 'Korekce zůstatku: '.$data['reason'],
            // Do rozpočtu se korekce nepočítá — nikdo za ni nic nekoupil.
            'excluded_from_budget' => true,
            'exclusion_reason' => 'Korekce zůstatku',
            'state' => 'approved',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['difference' => $rozdil] + $this->finance->balances($space));
    }

    public function destroyWallet(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $ucet = Wallet::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $pouzity = $this->pouzitiUctu($space, $ucet);

        abort_if($pouzity > 0, 409,
            "Účet má {$pouzity} zapsaných pohybů, proto ho nejde smazat — smazání by nechalo transakce bez účtu a zůstatky by přestaly sedět. Můžete ho odložit: přestane se nabízet a historie zůstane.");

        $ucet->delete();

        return response()->json($this->finance->balances($space));
    }

    // ---------------------------------------------------------------- cesty

    public function trips(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $cesty = FinanceProject::where('gallery_space_id', $space->id)
            ->where('kind', 'trip')->orderByDesc('starts_on')->get();

        return response()->json([
            'trips' => $cesty->map(fn (FinanceProject $c) => $this->cestaSeStavem($space, $c))->values(),
        ]);
    }

    public function storeTrip(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $data = $this->cestaData($request);

        $cesta = FinanceProject::create($data + [
            'gallery_space_id' => $space->id,
            'kind' => 'trip',
        ]);

        if ($request->boolean('activate')) {
            $cesta->aktivuj();
        }

        return response()->json(['trip' => $this->cestaSeStavem($space, $cesta->fresh())], 201);
    }

    public function updateTrip(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $cesta->update($this->cestaData($request, true));

        if ($request->boolean('activate')) {
            $cesta->aktivuj();
        }

        return response()->json(['trip' => $this->cestaSeStavem($space, $cesta->fresh())]);
    }

    /** Aktivní smí být jedna. Aktivace té druhé zhasne první. */
    public function activateTrip(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $cesta->aktivuj();

        return response()->json(['trip' => $this->cestaSeStavem($space, $cesta->fresh())]);
    }

    /**
     * Ukončení cesty i s tím, jak dopadla.
     *
     * Shrnutí zůstává v aplikaci, nevzniká z něj soubor ke stažení — zadání to
     * vylučuje a je to i praktičtější: čísla zůstanou proklikatelná na transakce,
     * ze kterých vznikla.
     */
    public function closeTrip(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $cesta->update(['state' => 'closed', 'is_active' => false]);

        return response()->json([
            'trip' => $this->cestaSeStavem($space, $cesta->fresh()),
            'summary' => $this->shrnutiCesty($space, $cesta),
        ]);
    }

    public function tripSummary(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'trip' => $this->cestaSeStavem($space, $cesta),
            'summary' => $this->shrnutiCesty($space, $cesta),
        ]);
    }

    public function destroyTrip(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $pocet = Transaction::where('gallery_space_id', $space->id)
            ->where('finance_project_id', $cesta->id)->count();

        abort_if($pocet > 0, 409,
            "K cestě je přiřazeno {$pocet} záznamů. Smazat ji jde až potom, co se od nich odpojí — jinak by zůstaly bez cesty a v jejím shrnutí by chyběly.");

        $cesta->delete();

        return response()->json(['deleted' => true]);
    }

    // ------------------------------------------------------------ kategorie

    /**
     * Seznam kategorií pro správu.
     *
     * Na rozdíl od číselníku pro formulář vrací i odložené a u každé počet použití —
     * podle něj se pozná, kterou jde ještě smazat a která už je v historii.
     */
    public function categories(Request $request): JsonResponse
    {
        return response()->json(['categories' => $this->kategorie($this->space($request))]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'kind' => 'required|in:expense,income',
            'icon' => 'nullable|string|max:40',
            'color' => 'nullable|string|max:20',
            'is_favourite' => 'sometimes|boolean',
        ]);

        FinanceCategory::create($data + [
            'gallery_space_id' => $space->id,
            'sort_order' => (int) FinanceCategory::where('gallery_space_id', $space->id)->max('sort_order') + 10,
        ]);

        return response()->json(['categories' => $this->kategorie($space)], 201);
    }

    public function updateCategory(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $k = FinanceCategory::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $k->update($request->validate([
            'name' => 'sometimes|string|max:80',
            'icon' => 'nullable|string|max:40',
            'color' => 'nullable|string|max:20',
            'is_favourite' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
            'default_wallet_id' => 'nullable|integer',
            'default_split' => 'nullable|string|max:20',
        ]));

        return response()->json(['categories' => $this->kategorie($space)]);
    }

    /**
     * Smazání kategorie.
     *
     * Použitou kategorii nelze smazat — u starých výdajů by zůstala díra a rozpad po
     * kategoriích by se rozešel se součtem výdajů. Kdo ji nechce vidět v nabídce, ať
     * ji odloží; u historie zůstane.
     */
    public function destroyCategory(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $k = FinanceCategory::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $pocet = Transaction::where('gallery_space_id', $space->id)->where('category_id', $k->id)->count();

        abort_if($pocet > 0, 409,
            "Kategorie je použitá u {$pocet} záznamů. Smazat ji nejde — u starých výdajů by zůstala díra. Můžete ji odložit, ať se přestane nabízet.");

        $k->delete();

        return response()->json(['categories' => $this->kategorie($space)]);
    }

    // ------------------------------------------------------------- partneři

    public function storePartner(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:160',
            'user_id' => 'nullable|integer',
        ]);

        Partner::create([
            'gallery_space_id' => $space->id,
            'kind' => 'person',
            'name' => $data['name'],
            'user_id' => $data['user_id'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'partners' => Partner::where('gallery_space_id', $space->id)->where('is_active', true)
                ->orderBy('name')->get(['id', 'uuid', 'name', 'kind']),
        ], 201);
    }

    // ------------------------------------------------------------- pomocné

    private function cestaData(Request $request, bool $uprava = false): array
    {
        $pravidlo = $uprava ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => "{$pravidlo}|string|max:160",
            'country' => 'nullable|string|max:80',
            'city' => 'nullable|string|max:120',
            'starts_on' => "{$pravidlo}|date",
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'base_currency' => "{$pravidlo}|string|size:3",
            'budget_amount' => 'nullable|numeric|min:0',
            'reserve_amount' => 'nullable|numeric|min:0',
            'default_wallet_id' => 'nullable|integer',
            'state' => 'sometimes|in:draft,active,closed',
            'note' => 'nullable|string|max:2000',
        ]);

        if (isset($data['base_currency'])) {
            $data['base_currency'] = strtoupper($data['base_currency']);
        }

        // Rezerva nesmí být větší než rozpočet — pak by „bezpečně na den" vyšlo záporně
        // hned první den a nedalo se z toho nic vyčíst.
        if (! empty($data['budget_amount']) && ! empty($data['reserve_amount'])
            && (float) $data['reserve_amount'] > (float) $data['budget_amount']) {
            abort(422, 'Rezerva nemůže být větší než rozpočet — pak by na útratu nezbylo nic už první den.');
        }

        unset($data['activate']);

        return $data;
    }

    private function cestaSeStavem(GallerySpace $space, FinanceProject $c): array
    {
        $limit = $c->budget_amount !== null ? (float) $c->budget_amount : null;
        $mena = $c->base_currency;

        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->where('finance_project_id', $c->id)->get();

        $utraceno = (float) $pohyby
            ->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena)
            ->sum('amount_from')
            + $pohyby->sum(fn (Transaction $t) => ($t->fee_currency ?? $t->currency_from) === $mena ? $t->feePaidExtra() : 0);

        $dni = $c->starts_on && $c->ends_on ? (int) $c->starts_on->diffInDays($c->ends_on) + 1 : null;
        $uteklo = $c->starts_on ? max(0, (int) $c->starts_on->diffInDays(Carbon::today(), false) + 1) : 0;

        return [
            'uuid' => $c->uuid,
            'name' => $c->name,
            'country' => $c->country,
            'city' => $c->city,
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'days_total' => $dni,
            'days_left' => $c->dniDoKonce(),
            'currency' => $mena,
            'budget' => $limit,
            'reserve' => $c->reserve_amount !== null ? (float) $c->reserve_amount : null,
            'spent' => round($utraceno, 2),
            'remaining' => $limit !== null ? round($limit - $utraceno, 2) : null,
            'percent' => $limit > 0 ? min(999, (int) round($utraceno / $limit * 100)) : null,
            'per_day_so_far' => $uteklo > 0 ? round($utraceno / min($uteklo, $dni ?? $uteklo), 2) : null,
            'safe_daily' => $limit !== null && $c->starts_on
                ? $this->finance->safeDaily($limit, $utraceno, (float) ($c->reserve_amount ?? 0), $c->starts_on, $c->ends_on)
                : null,
            'default_wallet_id' => $c->default_wallet_id,
            'is_active' => (bool) $c->is_active,
            'state' => $c->state,
            'note' => $c->note,
            'transactions' => $pohyby->count(),
        ];
    }

    /** Jak cesta dopadla — čísla, ne soubor. */
    private function shrnutiCesty(GallerySpace $space, FinanceProject $c): array
    {
        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->where('finance_project_id', $c->id)
            ->with(['category:id,name,color', 'walletFrom:id,name,currency,partner_id'])
            ->get();

        $mena = $c->base_currency;
        $vydaje = $pohyby->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena);

        $poDnech = $vydaje->groupBy(fn (Transaction $t) => $t->occurred_at->toDateString())
            ->map(fn ($s) => (float) $s->sum('amount_from'));

        $smeny = $pohyby->where('type', 'exchange');
        $kurzy = $smeny->map(fn (Transaction $t) => $this->finance->exchangeRate($t))->filter();

        return [
            'currency' => $mena,
            'budget' => $c->budget_amount !== null ? (float) $c->budget_amount : null,
            'spent' => round((float) $vydaje->sum('amount_from'), 2),
            'difference' => $c->budget_amount !== null
                ? round((float) $c->budget_amount - (float) $vydaje->sum('amount_from'), 2)
                : null,
            'per_day' => $poDnech->count() > 0 ? round($poDnech->avg(), 2) : 0.0,
            'top_categories' => $this->finance->byCategory($pohyby, $mena),
            'most_expensive_day' => $poDnech->count() > 0
                ? ['date' => $poDnech->keys()[$poDnech->values()->search($poDnech->max())], 'amount' => round($poDnech->max(), 2)]
                : null,
            'exchanged' => $smeny->groupBy('currency_from')
                ->map(fn ($s, $m) => ['currency' => $m, 'amount' => round((float) $s->sum('amount_from'), 2)])->values(),
            'average_rate' => $kurzy->count() > 0 ? round($kurzy->avg('effective'), 4) : null,
            'fees' => $pohyby->groupBy(fn (Transaction $t) => $t->fee_currency ?? $t->currency_from)
                ->map(fn ($s, $m) => ['currency' => $m, 'amount' => round($s->sum(fn (Transaction $t) => $t->feePaidExtra()), 2)])
                ->filter(fn ($r) => $r['amount'] > 0)->values(),
            'partner_balance' => $this->finance->partnerBalance(
                $pohyby,
                Partner::where('gallery_space_id', $space->id)->where('is_active', true)->get(),
            ),
            'transactions' => $pohyby->count(),
        ];
    }

    private function pouzitiUctu(GallerySpace $space, Wallet $ucet): int
    {
        return Transaction::where('gallery_space_id', $space->id)
            ->where(fn ($q) => $q->where('wallet_from_id', $ucet->id)->orWhere('wallet_to_id', $ucet->id))
            ->count();
    }

    private function kategorie(GallerySpace $space)
    {
        return FinanceCategory::where('gallery_space_id', $space->id)
            ->orderBy('sort_order')->get()
            ->map(fn (FinanceCategory $k) => [
                'uuid' => $k->uuid, 'id' => $k->id, 'name' => $k->name, 'kind' => $k->kind,
                'icon' => $k->icon, 'color' => $k->color,
                'is_favourite' => $k->is_favourite, 'is_active' => $k->is_active,
                'used' => Transaction::where('gallery_space_id', $space->id)->where('category_id', $k->id)->count(),
            ])->values();
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }
}
