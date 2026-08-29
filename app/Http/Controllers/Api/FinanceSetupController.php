<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceAccess;
use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\FinanceRecurring;
use App\Models\FinanceTemplate;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\FinanceService;
use App\Services\Finance\RecurringService;
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

    /**
     * Detail účtu — kde se ten zůstatek vzal.
     *
     * Vývoj se počítá **zpětně od dneška**, ne dopředu od počátečního stavu. Zůstatek
     * ke konci je jistý (je to ten, co ukazuje seznam účtů) a dopočítat z něj minulost
     * dá vždycky navazující řadu. Opačně by se každá chybějící transakce projevila
     * jako trvalý posun celé křivky.
     */
    public function walletDetail(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $ucet = Wallet::where('gallery_space_id', $space->id)->where('uuid', $uuid)
            ->with('partner:id,name')->firstOrFail();

        $filtr = FinanceFilter::zDotazu($request->all(), $space);

        $vsechny = Transaction::where('gallery_space_id', $space->id)
            ->where(fn ($q) => $q->where('wallet_from_id', $ucet->id)->orWhere('wallet_to_id', $ucet->id))
            ->with(['walletFrom:id,uuid,name,currency', 'walletTo:id,uuid,name,currency',
                'category:id,uuid,name,color,icon', 'payer:id,name', 'project:id,name,uuid', 'shares'])
            ->orderBy('occurred_at')->orderBy('id')
            ->get();

        $zustatek = (float) collect($this->finance->balances($space)['wallets'])
            ->firstWhere('uuid', $ucet->uuid)['balance'];

        return response()->json([
            'wallet' => [
                'uuid' => $ucet->uuid,
                'name' => $ucet->name,
                'kind' => $ucet->kind,
                'currency' => $ucet->currency,
                'owner' => $ucet->partner?->name,
                'opening_balance' => (float) $ucet->opening_balance,
                'balance' => $zustatek,
                'is_active' => (bool) $ucet->is_active,
                'transactions' => $vsechny->count(),
            ],
            'history' => $this->vyvojZustatku($ucet, $vsechny, $zustatek),
            'period' => $this->obdobiUctu($ucet, $vsechny, $filtr),
            'recent' => $this->pohybyUctu($ucet, $vsechny->reverse()->take(30)),
        ]);
    }

    /**
     * Zůstatek den po dni za posledních devadesát dnů.
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $pohyby
     */
    private function vyvojZustatku(Wallet $ucet, $pohyby, float $konecny): array
    {
        $od = Carbon::today()->subDays(89);
        $dnes = Carbon::today();

        // Denní změny zůstatku, aby se dalo jít zpětně.
        $zmeny = [];

        foreach ($pohyby as $t) {
            /** @var Transaction $t */
            $den = $t->occurred_at->toDateString();
            $zmeny[$den] ??= 0.0;

            if ($t->wallet_from_id === $ucet->id) {
                $zmeny[$den] -= (float) $t->amount_from + $t->feePaidExtra();
            }

            if ($t->wallet_to_id === $ucet->id) {
                $zmeny[$den] += (float) $t->amount_to;
            }
        }

        // Od konce dozadu: k dnešku známe zůstatek, každý krok zpět odečte změnu dne.
        $body = [];
        $stav = $konecny;
        $den = $dnes->copy();

        while ($den->greaterThanOrEqualTo($od)) {
            $klic = $den->toDateString();
            array_unshift($body, ['date' => $klic, 'balance' => round($stav, 2)]);
            $stav -= $zmeny[$klic] ?? 0;
            $den->subDay();
        }

        return $body;
    }

    /** Souhrn pohybů účtu za zvolené období. */
    private function obdobiUctu(Wallet $ucet, $pohyby, FinanceFilter $filtr): array
    {
        $vObdobi = $pohyby->filter(fn (Transaction $t) => $t->occurred_at->betweenIncluded(
            $filtr->od, $filtr->do ?? Carbon::today()->addYears(50),
        ));

        $prislo = (float) $vObdobi->filter(fn (Transaction $t) => $t->wallet_to_id === $ucet->id)->sum('amount_to');
        $odeslo = (float) $vObdobi->filter(fn (Transaction $t) => $t->wallet_from_id === $ucet->id)
            ->sum(fn (Transaction $t) => (float) $t->amount_from + $t->feePaidExtra());

        return [
            'label' => $filtr->popis,
            'currency' => $ucet->currency,
            'in' => round($prislo, 2),
            'out' => round($odeslo, 2),
            'net' => round($prislo - $odeslo, 2),
            'count' => $vObdobi->count(),
        ];
    }

    /** @param  \Illuminate\Support\Collection<int, Transaction>  $pohyby */
    private function pohybyUctu(Wallet $ucet, $pohyby): array
    {
        return $pohyby->map(function (Transaction $t) use ($ucet) {
            // Znaménko z pohledu tohohle účtu — u převodu mezi vlastními účty je
            // táž transakce jednou minus a podruhé plus a bez toho by nešlo poznat,
            // která strana se právě prohlíží.
            $prichozi = $t->wallet_to_id === $ucet->id;
            $castka = $prichozi ? (float) $t->amount_to : (float) $t->amount_from;

            return [
                'uuid' => $t->uuid,
                'occurred_at' => $t->occurred_at->toDateString(),
                'type' => $t->type,
                'direction' => $prichozi ? 'in' : 'out',
                'amount' => round($castka, 2),
                'currency' => $ucet->currency,
                'fee' => $prichozi ? 0.0 : round($t->feePaidExtra(), 2),
                'category' => $t->category?->name,
                'color' => $t->category?->color,
                'counterparty' => $t->counterparty,
                'description' => $t->description,
                'other_side' => $prichozi ? $t->walletFrom?->name : $t->walletTo?->name,
                'trip' => $t->project?->name,
            ];
        })->values()->all();
    }

    // ---------------------------------------------------------------- cesty

    public function trips(Request $request): JsonResponse
    {
        $space = $this->space($request);

        // Jen to, co uživatel smí vidět: společné, vlastní a nasdílené.
        $cesty = FinanceAccess::viditelne(
            FinanceProject::where('gallery_space_id', $space->id)->where('kind', 'trip'),
            'trip', $request->user()->id,
        )->orderByDesc('starts_on')->get();

        return response()->json([
            'trips' => $cesty->map(fn (FinanceProject $c) => $this->cestaSeStavem($space, $c))->values(),
            'members' => $this->clenove($space),
        ]);
    }

    /**
     * Sdílení cesty nebo rozpočtu.
     *
     * Posílá se celý seznam, ne jeden přírůstek. Odebrání přístupu je stejně častá
     * akce jako přidání a dvě cesty by znamenaly dvě místa, kde se dá zapomenout,
     * že se má něco odebrat.
     */
    public function shareTrip(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $this->overitPravoUpravy('trip', $cesta->id, $cesta->owner_user_id, $request->user()->id);

        $data = $request->validate([
            'owner_user_id' => 'nullable|integer',
            'access' => 'nullable|array|max:10',
            'access.*.user_id' => 'required|integer',
            'access.*.can_edit' => 'sometimes|boolean',
        ]);

        if (array_key_exists('owner_user_id', $data)) {
            $cesta->update(['owner_user_id' => $data['owner_user_id'] ?: null]);
        }

        $this->ulozitPristup($space, 'trip', $cesta->id, $data['access'] ?? [], $cesta->owner_user_id);

        return response()->json(['trip' => $this->cestaSeStavem($space, $cesta->fresh())]);
    }

    /** @return array<int, array<string, mixed>> */
    private function clenove(GallerySpace $space): array
    {
        return $space->members()->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
    }

    private function overitPravoUpravy(string $druh, int $id, ?int $vlastnik, int $uzivatel): void
    {
        abort_unless(FinanceAccess::smiUpravit($druh, $id, $vlastnik, $uzivatel), 403,
            'Tohle patří někomu jinému a nemáte právo to měnit. Můžete si o něj říct.');
    }

    /**
     * Cesta podle uuid — a když je zadaný uživatel, ověří i právo do ní zapsat.
     *
     * Jedna cesta pro všechny zapisující akce: úpravu, aktivaci, ukončení i smazání.
     * Kdyby si každá dělala kontrolu sama, další přibylá akce by na ni zapomněla.
     */
    private function cestaProUpravu(GallerySpace $space, string $uuid, ?int $ja = null): FinanceProject
    {
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        if ($ja !== null) {
            $this->overitPravoUpravy('trip', $cesta->id, $cesta->owner_user_id, $ja);
        }

        return $cesta;
    }

    /**
     * Přepíše seznam přístupů.
     *
     * Vlastník se do seznamu nepřidává — přístup má z podstaty a mít ho tam dvakrát
     * by znamenalo, že si ho jde odebrat a přijít o vlastní rozpočet.
     *
     * @param  array<int, array<string, mixed>>  $pristupy
     */
    private function ulozitPristup(GallerySpace $space, string $druh, int $id, array $pristupy, ?int $vlastnik): void
    {
        FinanceAccess::where('subject_type', $druh)->where('subject_id', $id)->delete();

        $clenove = collect($this->clenove($space))->pluck('id');

        foreach ($pristupy as $p) {
            $userId = (int) $p['user_id'];

            if ($userId === $vlastnik || ! $clenove->contains($userId)) {
                continue;
            }

            FinanceAccess::create([
                'gallery_space_id' => $space->id,
                'subject_type' => $druh,
                'subject_id' => $id,
                'user_id' => $userId,
                'can_edit' => (bool) ($p['can_edit'] ?? false),
            ]);
        }
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

        // Přístupy se ukládají spolu se založením: kdo cestu předá druhému, ztratí k ní
        // právo hned tímhle uložením a samostatný požadavek na sdílení by mu vrátil 403.
        $this->ulozitPristup($space, 'trip', $cesta->id, $request->input('access', []), $cesta->owner_user_id);

        return response()->json(['trip' => $this->cestaSeStavem($space, $cesta->fresh())], 201);
    }

    public function updateTrip(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = $this->cestaProUpravu($space, $uuid, $request->user()->id);

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
        $cesta = $this->cestaProUpravu($space, $uuid, $request->user()->id);

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
        $cesta = $this->cestaProUpravu($space, $uuid, $request->user()->id);

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

    /**
     * Detail cesty — všechno o jednom pobytu na jedné obrazovce.
     *
     * Denní vývoj se počítá jen do dneška, ne do konce cesty. Nuly za dny, které
     * ještě nebyly, by vypadaly jako dny, kdy se nic neutratilo, a průměr by srazily
     * na polovinu.
     */
    public function tripDetail(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->where('finance_project_id', $cesta->id)
            ->with(['walletFrom:id,uuid,name,currency,partner_id,kind', 'walletTo:id,uuid,name,currency',
                'category:id,uuid,name,color,icon', 'payer:id,name', 'shares', 'refundOf:id,category_id'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->get();

        $mena = $cesta->base_currency;
        $stav = $this->cestaSeStavem($space, $cesta);

        // Denní útrata do dneška, případně do konce, pokud už cesta skončila.
        $konecVyvoje = $cesta->ends_on && $cesta->ends_on->lessThan(Carbon::today())
            ? $cesta->ends_on
            : Carbon::today();

        $denni = $cesta->starts_on && $cesta->starts_on->lessThanOrEqualTo($konecVyvoje)
            ? $this->finance->daily($pohyby, $mena, $cesta->starts_on, $konecVyvoje)
            : [];

        $partneri = Partner::where('gallery_space_id', $space->id)->where('is_active', true)->get();

        return response()->json([
            'trip' => $stav,
            'daily' => $denni,
            'categories' => $this->finance->byCategory($pohyby, $mena),
            'partners' => $this->finance->partnerBalance($pohyby, $partneri),
            'wallets' => $pohyby
                ->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena && $t->walletFrom)
                ->groupBy('wallet_from_id')
                ->map(fn ($s) => [
                    'name' => $s->first()->walletFrom->name,
                    'kind' => $s->first()->walletFrom->kind,
                    'amount' => round((float) $s->sum('amount_from'), 2),
                    'count' => $s->count(),
                    'currency' => $mena,
                ])->sortByDesc('amount')->values(),
            'exchanges' => $pohyby->where('type', 'exchange')->map(fn (Transaction $t) => [
                'uuid' => $t->uuid,
                'occurred_at' => $t->occurred_at->toDateString(),
                'provider' => $t->provider,
                'from' => ['amount' => (float) $t->amount_from, 'currency' => $t->currency_from],
                'to' => ['amount' => (float) $t->amount_to, 'currency' => $t->currency_to],
                'rate' => $this->finance->exchangeRate($t),
            ])->values(),
            'largest' => $pohyby
                ->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena)
                ->sortByDesc('amount_from')->take(6)
                ->map(fn (Transaction $t) => [
                    'uuid' => $t->uuid,
                    'amount' => (float) $t->amount_from,
                    'currency' => $mena,
                    'occurred_at' => $t->occurred_at->toDateString(),
                    'category' => $t->category?->name,
                    'counterparty' => $t->counterparty,
                ])->values(),
            'prediction' => $this->predpovedCesty($cesta, $pohyby, $stav),
            'transactions' => $pohyby->count(),
        ]);
    }

    /**
     * Jak cesta pravděpodobně dopadne.
     *
     * Tempo se počítá z **uplynulých** dnů, ne z celé délky pobytu. Dělit útratu
     * délkou cesty by třetí den z čtrnáctidenního pobytu dalo pětinové tempo a
     * předpověď, že vyjde všechno, i kdyby se utrácelo dvojnásobně.
     *
     * Spolehlivost se hlásí slovem, ne procentem. Procento by naznačovalo přesnost,
     * kterou odhad z pěti dnů nemá — a lidé by podle něj rozhodovali.
     */
    private function predpovedCesty(FinanceProject $cesta, $pohyby, array $stav): ?array
    {
        if ($cesta->budget_amount === null || ! $cesta->starts_on || ! $cesta->ends_on) {
            return null;
        }

        $dnes = Carbon::today();
        $uteklo = max(0, (int) $cesta->starts_on->diffInDays($dnes->min($cesta->ends_on), false) + 1);
        $celkem = (int) $cesta->starts_on->diffInDays($cesta->ends_on) + 1;

        if ($uteklo <= 0) {
            return ['quality' => 'not_started', 'expected_total' => null, 'expected_left' => null, 'runs_out_on' => null];
        }

        $tempo = (float) $stav['spent'] / $uteklo;
        $ocekavane = $tempo * $celkem;
        $limit = (float) $cesta->budget_amount;

        $dojdouZa = $tempo > 0 ? (int) floor(($limit - (float) $stav['spent']) / $tempo) : null;

        return [
            'quality' => $uteklo < 3 ? 'low' : ($uteklo < 7 ? 'rough' : 'stable'),
            'days_elapsed' => $uteklo,
            'days_total' => $celkem,
            'pace' => round($tempo, 2),
            'expected_total' => round($ocekavane, 2),
            'expected_left' => round($limit - $ocekavane, 2),
            'runs_out_on' => $dojdouZa !== null && $dojdouZa >= 0 && $dojdouZa < ($celkem - $uteklo)
                ? $dnes->copy()->addDays($dojdouZa)->toDateString()
                : null,
            'currency' => $cesta->base_currency,
        ];
    }

    public function destroyTrip(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $cesta = $this->cestaProUpravu($space, $uuid, $request->user()->id);

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

    // ---------------------------------------------------- pravidelné platby

    /**
     * Předpisy pravidelných plateb.
     *
     * Vrací i to, co z nich ještě přijde do konce aktivní cesty — to je celý důvod,
     * proč předpisy existují. Čtyři nezaplacené nájmy jsou peníze, které už mají
     * majitele, i když ještě leží na účtu.
     */
    public function recurring(Request $request): JsonResponse
    {
        $space = $this->space($request);

        // Dopsat, co mělo proběhnout. Jen do dneška — budoucí splátka z účtu neodešla.
        app(RecurringService::class)->generovat($space);

        return response()->json(['recurring' => $this->predpisy($space)]);
    }

    public function storeRecurring(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => 'sometimes|in:expense,income',
            'amount' => 'required|numeric|min:0.01',
            'wallet_uuid' => 'required|uuid',
            'category_uuid' => 'nullable|uuid',
            'trip_uuid' => 'nullable|uuid',
            'day_of_month' => 'required|integer|min:1|max:31',
            'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'split' => 'nullable|in:equal,first,second',
            'payer_partner_id' => 'nullable|integer',
        ]);

        $ucet = Wallet::where('gallery_space_id', $space->id)->where('uuid', $data['wallet_uuid'])->firstOrFail();
        $cesta = ! empty($data['trip_uuid'])
            ? FinanceProject::where('gallery_space_id', $space->id)->where('uuid', $data['trip_uuid'])->first()
            : null;

        $predpis = FinanceRecurring::create([
            'gallery_space_id' => $space->id,
            'name' => $data['name'],
            'type' => $data['type'] ?? 'expense',
            'amount' => $data['amount'],
            // Měna se bere z účtu, ne z formuláře. Nájem placený z eurové karty je
            // v eurech a nabídnout u něj jinou měnu by znamenalo zápis, který nesedí
            // s účtem, ze kterého odchází.
            'currency' => $ucet->currency,
            'wallet_id' => $ucet->id,
            'finance_category_id' => $this->idKategorie($space, $data['category_uuid'] ?? null),
            'finance_project_id' => $cesta?->id,
            'payer_partner_id' => $data['payer_partner_id'] ?? null,
            'split' => $data['split'] ?? null,
            'day_of_month' => $data['day_of_month'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        // Splátky, které už měly proběhnout, se dopíšou hned — předpis založený
        // uprostřed pobytu má doplnit i to, co uteklo.
        app(RecurringService::class)->generovat($space);

        return response()->json(['recurring' => $this->predpisy($space)], 201);
    }

    public function updateRecurring(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $predpis = FinanceRecurring::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $predpis->update($request->validate([
            'name' => 'sometimes|string|max:120',
            'amount' => 'sometimes|numeric|min:0.01',
            'day_of_month' => 'sometimes|integer|min:1|max:31',
            'ends_on' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json(['recurring' => $this->predpisy($space)]);
    }

    /**
     * Smazání předpisu.
     *
     * Zapsané splátky zůstávají. Jsou to peníze, které opravdu odešly — smazat je
     * spolu s předpisem by změnilo zůstatky na účtech zpětně a nikdo by nepoznal proč.
     * Předpis jen přestane vyrábět další.
     */
    public function destroyRecurring(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $predpis = FinanceRecurring::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $pocet = Transaction::where('recurring_id', $predpis->id)->count();
        $predpis->delete();

        return response()->json([
            'recurring' => $this->predpisy($space),
            'kept' => $pocet,
        ]);
    }

    private function predpisy(GallerySpace $space)
    {
        $cesta = $this->finance->activeTrip($space);
        $sluzba = app(RecurringService::class);

        return FinanceRecurring::where('gallery_space_id', $space->id)
            ->with(['wallet:id,uuid,name,currency', 'category:id,uuid,name,color', 'project:id,uuid,name'])
            ->orderByDesc('is_active')->orderBy('day_of_month')
            ->get()
            ->map(function (FinanceRecurring $p) use ($cesta) {
                $doKonce = $cesta?->ends_on
                    ? $p->zbyvaDoKonce(Carbon::today(), $cesta->ends_on)
                    : 0.0;

                return [
                    'uuid' => $p->uuid,
                    'name' => $p->name,
                    'type' => $p->type,
                    'amount' => (float) $p->amount,
                    'currency' => $p->currency,
                    'wallet' => $p->wallet ? ['uuid' => $p->wallet->uuid, 'name' => $p->wallet->name] : null,
                    'category' => $p->category ? ['uuid' => $p->category->uuid, 'name' => $p->category->name, 'color' => $p->category->color] : null,
                    'trip' => $p->project?->name,
                    'day_of_month' => $p->day_of_month,
                    'starts_on' => $p->starts_on->toDateString(),
                    'ends_on' => $p->ends_on?->toDateString(),
                    'split' => $p->split,
                    'is_active' => (bool) $p->is_active,
                    'paid_count' => Transaction::where('recurring_id', $p->id)->count(),
                    'remaining_to_trip_end' => round($doKonce, 2),
                ];
            })->values();
    }

    // -------------------------------------------------------------- šablony

    /**
     * Šablony rychlého zápisu.
     *
     * Předvyplní všechno kromě částky. Ta se nepředvyplňuje nikdy — je to jediný údaj,
     * který se pokaždé liší, a nabídnout u něj číslo by znamenalo, že ho někdo jednou
     * přehlédne a zapíše cizí částku.
     *
     * Řadí se podle použití, ne podle abecedy. Šablona, která se používá denně, má být
     * první; ta z loňska může být poslední.
     */
    public function templates(Request $request): JsonResponse
    {
        return response()->json(['templates' => $this->sablony($this->space($request))]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'type' => 'sometimes|in:expense,income',
            'category_uuid' => 'nullable|uuid',
            'wallet_uuid' => 'nullable|uuid',
            'payer_partner_id' => 'nullable|integer',
            'split' => 'nullable|in:equal,first,second',
        ]);

        FinanceTemplate::create([
            'gallery_space_id' => $space->id,
            'name' => $data['name'],
            'type' => $data['type'] ?? 'expense',
            'finance_category_id' => $this->idKategorie($space, $data['category_uuid'] ?? null),
            'wallet_id' => $this->idUctu($space, $data['wallet_uuid'] ?? null),
            'payer_partner_id' => $data['payer_partner_id'] ?? null,
            'split' => $data['split'] ?? null,
            'sort_order' => (int) FinanceTemplate::where('gallery_space_id', $space->id)->max('sort_order') + 10,
        ]);

        return response()->json(['templates' => $this->sablony($space)], 201);
    }

    /** Započítá použití, aby se často používané šablony držely nahoře. */
    public function useTemplate(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);

        FinanceTemplate::where('gallery_space_id', $space->id)->where('uuid', $uuid)
            ->increment('used_count');

        return response()->json(['ok' => true]);
    }

    public function destroyTemplate(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);

        FinanceTemplate::where('gallery_space_id', $space->id)->where('uuid', $uuid)->delete();

        return response()->json(['templates' => $this->sablony($space)]);
    }

    private function sablony(GallerySpace $space)
    {
        return FinanceTemplate::where('gallery_space_id', $space->id)
            ->with(['category:id,uuid,name,color', 'wallet:id,uuid,name,currency', 'payer:id,name'])
            ->orderByDesc('used_count')->orderBy('sort_order')
            ->get()
            ->map(fn (FinanceTemplate $s) => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'type' => $s->type,
                'category' => $s->category ? ['uuid' => $s->category->uuid, 'name' => $s->category->name, 'color' => $s->category->color] : null,
                'wallet' => $s->wallet ? ['uuid' => $s->wallet->uuid, 'name' => $s->wallet->name, 'currency' => $s->wallet->currency] : null,
                'payer' => $s->payer ? ['id' => $s->payer->id, 'name' => $s->payer->name] : null,
                'split' => $s->split,
                'used_count' => $s->used_count,
            ])->values();
    }

    private function idKategorie(GallerySpace $space, ?string $uuid): ?int
    {
        return $uuid
            ? FinanceCategory::where('gallery_space_id', $space->id)->where('uuid', $uuid)->value('id')
            : null;
    }

    private function idUctu(GallerySpace $space, ?string $uuid): ?int
    {
        return $uuid
            ? Wallet::where('gallery_space_id', $space->id)->where('uuid', $uuid)->value('id')
            : null;
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
            // Prázdné znamená společná cesta — dosavadní stav, ve kterém všechno vidí
            // oba. Vyplněné ji přiřadí jednomu a druhý ji uvidí, až mu ji nasdílí.
            'owner_user_id' => 'nullable|integer',
        ]);

        if (array_key_exists('owner_user_id', $data)) {
            $data['owner_user_id'] = $data['owner_user_id'] ?: null;
        }

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
            'owner_user_id' => $c->owner_user_id,
            'owner_name' => $c->owner_user_id ? optional(\App\Models\User::find($c->owner_user_id))->name : null,
            'access' => FinanceAccess::sdileniPro('trip', $c->id),
        ];
    }

    /** Jak cesta dopadla — čísla, ne soubor. */
    private function shrnutiCesty(GallerySpace $space, FinanceProject $c): array
    {
        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->where('finance_project_id', $c->id)
            ->with(['category:id,uuid,name,color', 'walletFrom:id,name,currency,partner_id'])
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
