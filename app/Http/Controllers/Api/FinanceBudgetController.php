<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\FinanceAccess;
use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Services\Finance\FinanceService;
use App\Services\Finance\RozdeleniService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rozpočty modulu — limity nad knihou.
 *
 * Rozpočet tu není plán, ze kterého se počítá; je to **strop**. Čerpání se bere
 * z transakcí, takže rozpočet a skutečnost se nemůžou rozejít — což je přesně to,
 * co se stane, když se útraty vedou zvlášť pro rozpočet a zvlášť pro účty.
 *
 * `scope = 'ledger'` odděluje tyhle od starých rozpočtů, které mají vlastní položky.
 * Bez toho by jedna služba musela u každého rozpočtu hádat, odkud brát útraty.
 */
class FinanceBudgetController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly RozdeleniService $rozdeleni,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $rozpocty = FinanceAccess::viditelne(
            Budget::where('gallery_space_id', $space->id)->where('scope', 'ledger'),
            'budget', $request->user()->id,
        )
            ->with('financeProject:id,uuid,name,starts_on,ends_on,base_currency')
            ->orderByDesc('starts_on')
            ->get();

        return response()->json([
            'budgets' => $rozpocty->map(fn (Budget $b) => $this->stav($space, $b, $request->user()->id))->values(),
            'members' => $space->members()->get(['users.id', 'users.name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    /**
     * Změna jedné vyhrazené částky.
     *
     * Vlastní koncový bod, ne celý formulář: upravit „na jídlo o padesát víc" je ta
     * nejčastější změna vůbec a přes celý formulář znamená otevřít okno, najít řádek
     * v seznamu patnácti kategorií a přepsat ho. Pak to nikdo nedělá a plán zestárne.
     *
     * Nula položku zruší. Vyhrazená částka nula a žádná vyhrazená částka je totéž a
     * dvojí zápis téhož by znamenal řádek, který v tabulce nic neříká.
     */
    public function setLimit(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $rozpocet = $this->rozpocet($space, $uuid, $request->user()->id);

        $data = $request->validate([
            'category_uuid' => 'required|uuid',
            'amount' => 'required|numeric|min:0',
            'priority' => 'sometimes|integer|min:1|max:999',
        ]);

        $kategorie = FinanceCategory::where('gallery_space_id', $space->id)
            ->where('uuid', $data['category_uuid'])->firstOrFail();

        $radek = DB::table('budget_category_limits')
            ->where('budget_id', $rozpocet->id)->where('finance_category_id', $kategorie->id);

        if ((float) $data['amount'] <= 0) {
            $radek->delete();
        } else {
            $radek->upsert([[
                'budget_id' => $rozpocet->id,
                'finance_category_id' => $kategorie->id,
                'amount' => $data['amount'],
                'priority' => (int) ($data['priority'] ?? $radek->value('priority') ?? 50),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['budget_id', 'finance_category_id'], ['amount', 'priority', 'updated_at']);
        }

        return response()->json(['budget' => $this->stav($space, $rozpocet->fresh(), $request->user()->id)]);
    }

    /**
     * Přerozdělení podle toho, jak se doopravdy utrácí.
     *
     * Návrh se počítá znovu tady, ne že by se převzal z prohlížeče. Mezi zobrazením
     * tabulky a klepnutím na tlačítko mohl někdo zapsat výdaj — a přepsat plán podle
     * čísel, která už neplatí, je horší než ho nechat být.
     */
    public function redistribute(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $rozpocet = $this->rozpocet($space, $uuid, $request->user()->id);

        $navrh = $this->stav($space, $rozpocet, $request->user()->id)['allocation']['release'];

        if ($navrh['moved'] <= 0) {
            return response()->json([
                'budget' => $this->stav($space, $rozpocet, $request->user()->id),
                'moved' => 0,
                'message' => 'Přerozdělovat není co — buď zatím není z čeho brát, nebo nikde nechybí.',
            ]);
        }

        DB::transaction(function () use ($space, $rozpocet, $navrh) {
            foreach ([...$navrh['givers'], ...$navrh['receivers']] as $zmena) {
                $kategorie = FinanceCategory::where('gallery_space_id', $space->id)
                    ->where('uuid', $zmena['category_uuid'])->first();

                if (! $kategorie) {
                    continue;
                }

                DB::table('budget_category_limits')
                    ->where('budget_id', $rozpocet->id)
                    ->where('finance_category_id', $kategorie->id)
                    ->update(['amount' => max(0, $zmena['new_planned']), 'updated_at' => now()]);
            }
        });

        return response()->json([
            'budget' => $this->stav($space, $rozpocet->fresh(), $request->user()->id),
            'moved' => $navrh['moved'],
            'message' => 'Přerozděleno '.number_format($navrh['moved'], 2, ',', ' ').' '.$navrh['currency'].'.',
        ]);
    }

    /**
     * Komu rozpočet patří a kdo do něj vidí.
     *
     * Posílá se celý seznam přístupů, ne přírůstek — odebrat přístup je stejně častá
     * potřeba jako přidat ho a dvě různé cesty by znamenaly dvě místa, kde se dá
     * odebrání zapomenout.
     */
    public function share(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $rozpocet = $this->rozpocet($space, $uuid);
        $ja = $request->user()->id;

        abort_unless(FinanceAccess::smiUpravit('budget', $rozpocet->id, $rozpocet->owner_user_id, $ja), 403,
            'Tenhle rozpočet patří někomu jinému a nemáte právo měnit, kdo do něj vidí.');

        $data = $request->validate([
            'owner_user_id' => 'nullable|integer',
            'access' => 'nullable|array|max:10',
            'access.*.user_id' => 'required|integer',
            'access.*.can_edit' => 'sometimes|boolean',
        ]);

        if (array_key_exists('owner_user_id', $data)) {
            $rozpocet->update([
                'owner_user_id' => $data['owner_user_id'] ?: null,
                'is_shared' => $data['owner_user_id'] ? false : true,
            ]);
        }

        $this->ulozPristup($space, $rozpocet, $data['access'] ?? []);

        return response()->json(['budget' => $this->stav($space, $rozpocet->fresh(), $ja)]);
    }

    /**
     * Přepíše seznam přístupů k rozpočtu.
     *
     * Volá se i ze zakládání, ne jen ze sdílení — kdo rozpočet předá druhému, ztratí
     * k němu právo hned tím uložením a druhý požadavek by mu vrátil 403. Jedno uložení
     * to řeší tím, že vlastnictví a přístupy vzniknou zároveň.
     *
     * @param  array<int, array<string, mixed>>  $pristupy
     */
    private function ulozPristup(GallerySpace $space, Budget $rozpocet, array $pristupy): void
    {
        FinanceAccess::where('subject_type', 'budget')->where('subject_id', $rozpocet->id)->delete();

        $clenove = $space->members()->pluck('users.id');

        foreach ($pristupy as $p) {
            $userId = (int) ($p['user_id'] ?? 0);

            // Vlastník se do přístupů nepíše — má je z podstaty a jako řádek navíc
            // by šel odebrat, čímž by přišel o vlastní rozpočet.
            if ($userId === $rozpocet->owner_user_id || ! $clenove->contains($userId)) {
                continue;
            }

            FinanceAccess::create([
                'gallery_space_id' => $space->id,
                'subject_type' => 'budget',
                'subject_id' => $rozpocet->id,
                'user_id' => $userId,
                'can_edit' => (bool) ($p['can_edit'] ?? false),
            ]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $data = $this->data($request);

        // Vlastník se zadává při zakládání: „společný" (null) je běžnější případ, ale
        // Makinčin německý rozpočet a Adriho život v Česku jsou dvě různé peněženky
        // a v jednom součtu nemají co dělat.
        $vlastnik = $request->input('owner_user_id') ?: null;

        $rozpocet = Budget::create($data + [
            'gallery_space_id' => $space->id,
            'owner_user_id' => $vlastnik,
            'scope' => 'ledger',
            'is_shared' => $vlastnik === null,
            'created_by' => $request->user()->id,
        ]);

        $this->ulozLimity($request, $space, $rozpocet);
        $this->ulozPristup($space, $rozpocet, $request->input('access', []));

        return response()->json(['budget' => $this->stav($space, $rozpocet->fresh(), $request->user()->id)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $rozpocet = $this->rozpocet($space, $uuid, $request->user()->id);

        $rozpocet->update($this->data($request, true));
        $this->ulozLimity($request, $space, $rozpocet);

        return response()->json(['budget' => $this->stav($space, $rozpocet->fresh(), $request->user()->id)]);
    }

    /**
     * Smazání rozpočtu.
     *
     * Transakce zůstanou. Rozpočet je jen strop nad nimi — smazat ho znamená přestat
     * měřit, ne přijít o útraty. Proto se tady na nic neptá a nic se neblokuje.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $rozpocet = $this->rozpocet($space, $uuid, $request->user()->id);

        DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->delete();
        $rozpocet->delete();

        return response()->json(['deleted' => true]);
    }

    private function data(Request $request, bool $uprava = false): array
    {
        $pravidlo = $uprava ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => "{$pravidlo}|string|max:160",
            'budget_kind' => "{$pravidlo}|in:monthly,trip",
            'currency' => "{$pravidlo}|string|size:3",
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'amount' => "{$pravidlo}|numeric|min:0",
            'reserve_amount' => 'nullable|numeric|min:0',
            'finance_project_id' => 'nullable|integer',
            'alert_thresholds' => 'nullable|string|max:40',
            'income_adds' => 'sometimes|boolean',
        ]);

        // Cesta se jede s pevnou sumou, takže každý příjem je navíc. U měsíčního
        // rozpočtu je výplata sám ten rozpočet a přičíst ji by znamenalo počítat
        // s dvojnásobkem. Výchozí hodnota jde přebít, ale musí sedět bez ptaní.
        if (! $uprava && ! $request->exists('income_adds')) {
            $data['income_adds'] = ($data['budget_kind'] ?? 'monthly') === 'trip';
        }

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        // Obrazovka zná cesty podle uuid, ne podle id — vnitřní čísla nemá kam vzít
        // a posílat je do prohlížeče by znamenalo vystavit pořadí v databázi.
        if ($request->filled('trip_uuid')) {
            $data['finance_project_id'] = FinanceProject::where('gallery_space_id', $this->space($request)->id)
                ->where('uuid', $request->input('trip_uuid'))->value('id');
        } elseif ($request->exists('trip_uuid')) {
            $data['finance_project_id'] = null;
        }

        if (! empty($data['amount']) && ! empty($data['reserve_amount'])
            && (float) $data['reserve_amount'] > (float) $data['amount']) {
            abort(422, 'Rezerva nemůže být větší než rozpočet — na útratu by nezbylo nic už první den.');
        }

        // Měsíční rozpočet nepotřebuje datum — období je aktuální měsíc a posouvá se
        // samo. Ptát se na něj by znamenalo zakládat nový každý první.
        if (($data['budget_kind'] ?? 'monthly') === 'monthly') {
            $data['starts_on'] = $data['starts_on'] ?? Carbon::today()->startOfMonth()->toDateString();
            $data['ends_on'] = null;
            $data['period_mode'] = 'rolling';
        } else {
            $data['period_mode'] = 'fixed';

            // Období si rozpočet na cestu bere z cesty. Ptát se na ně zvlášť by znamenalo
            // dvě data téhož pobytu, která se dřív nebo později rozejdou — a čerpání by
            // se pak počítalo za jiné dny, než za které cesta trvá.
            if (! empty($data['finance_project_id'])) {
                $cesta = FinanceProject::find($data['finance_project_id']);

                $data['starts_on'] = $data['starts_on'] ?? $cesta?->starts_on?->toDateString();
                $data['ends_on'] = $data['ends_on'] ?? $cesta?->ends_on?->toDateString();
            }

            abort_if(empty($data['starts_on']) && ! $uprava, 422,
                'Rozpočet na cestu potřebuje vědět, odkdy platí. Vyberte cestu, která má zadané datum začátku.');
        }

        // `amount` je v modelu `monthly_income`? Ne — limit má vlastní význam a ukládá
        // se do `starting_funds`, které přesně tohle znamená: kolik je k dispozici.
        if (isset($data['amount'])) {
            $data['starting_funds'] = $data['amount'];
            unset($data['amount']);
        }

        return $data;
    }

    private function ulozLimity(Request $request, GallerySpace $space, Budget $rozpocet): void
    {
        if (! $request->has('limits')) {
            return;
        }

        $limity = $request->validate([
            'limits' => 'array|max:40',
            'limits.*.category_uuid' => 'required|uuid',
            'limits.*.amount' => 'required|numeric|min:0',
            'limits.*.priority' => 'sometimes|integer|min:1|max:999',
        ])['limits'];

        DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->delete();

        foreach ($limity as $l) {
            $kategorie = FinanceCategory::where('gallery_space_id', $space->id)
                ->where('uuid', $l['category_uuid'])->first();

            if (! $kategorie || (float) $l['amount'] <= 0) {
                continue;
            }

            DB::table('budget_category_limits')->insert([
                'budget_id' => $rozpocet->id,
                'finance_category_id' => $kategorie->id,
                'amount' => $l['amount'],
                'priority' => (int) ($l['priority'] ?? 100),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Stav rozpočtu — kolik se z něj vyčerpalo a jestli to vyjde.
     *
     * Období měsíčního rozpočtu je aktuální měsíc, ne od založení. Bez toho by po
     * půl roce stálo proti měsíčnímu limitu půl roku útrat a rozpočet by hlásil
     * šestinásobné překročení, které se nestalo.
     */
    private function stav(GallerySpace $space, Budget $b, ?int $ja = null): array
    {
        $mena = $b->currency;
        $limit = (float) ($b->starting_funds ?? 0);
        $rezerva = (float) ($b->reserve_amount ?? 0);

        $cesta = $b->budget_kind === 'trip' && $b->finance_project_id
            ? FinanceProject::find($b->finance_project_id)
            : null;

        if ($b->budget_kind === 'monthly') {
            $od = Carbon::today()->startOfMonth();
            $do = Carbon::today()->endOfMonth();
        } else {
            $od = $cesta?->starts_on ?? $b->starts_on;
            $do = $cesta?->ends_on ?? $b->ends_on;
        }

        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->with(['category:id,uuid,name,color,icon', 'refundOf:id,category_id'])
            ->whereDate('occurred_at', '>=', $od)
            ->when($do, fn ($q) => $q->whereDate('occurred_at', '<=', $do))
            ->when($cesta, fn ($q) => $q->where('finance_project_id', $cesta->id))
            ->get();

        $utraceno = (float) $pohyby
            ->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena)
            ->sum('amount_from')
            + $pohyby->sum(fn (Transaction $t) => ($t->fee_currency ?? $t->currency_from) === $mena ? $t->feePaidExtra() : 0);

        $vraceno = (float) $pohyby
            ->filter(fn (Transaction $t) => $t->type === 'income' && $t->refund_of_id !== null && $t->currency_to === $mena)
            ->sum('amount_to');

        $ciste = max(0, $utraceno - $vraceno);

        $rozpad = $this->finance->byCategory($pohyby, $mena);
        $limity = DB::table('budget_category_limits')
            ->where('budget_id', $b->id)
            ->get()->keyBy('finance_category_id');

        // Kategorie s vyhrazenou částkou se ukazují i tehdy, když se v nich ještě nic
        // neutratilo — jinak by nová položka zmizela a vypadalo by to, že se neuložila.
        $polozky = FinanceCategory::where('gallery_space_id', $space->id)
            ->whereIn('id', $limity->keys())->get()
            ->map(fn (FinanceCategory $k) => [
                'category_id' => $k->id,
                'category_uuid' => $k->uuid,
                'name' => $k->name,
                'color' => $k->color,
                'planned' => (float) $limity[$k->id]->amount,
                'priority' => (int) ($limity[$k->id]->priority ?? 100),
                'spent' => (float) (collect($rozpad)->firstWhere('category_id', $k->id)['amount'] ?? 0),
                // Kolik zápisů kategorii tvoří. Z jednoho se tempo odvodit nedá.
                'count' => (int) (collect($rozpad)->firstWhere('category_id', $k->id)['count'] ?? 0),
            ])->all();

        // Příjem zapsaný v období se rozdělí sám. Proto se tu nepočítá se stropem, ale
        // s tím, co je opravdu k dispozici — jinak by nečekaná výplata ležela stranou
        // a rozpočet by dál tvrdil, že na výlety není.
        $prijem = $b->income_adds
            ? (float) $pohyby
                ->filter(fn (Transaction $t) => $t->type === 'income' && $t->refund_of_id === null && $t->currency_to === $mena)
                ->sum('amount_to')
            : 0.0;

        // Dny se počítají dřív než rozdělení — předpověď na kategorie z nich vychází
        // a musí to být tytéž dny, jaké používá odhad na celý rozpočet. Dvě různá
        // čísla by znamenala, že součet předpovědí nesedí s předpovědí součtu.
        $uteklo = max(1, (int) $od->diffInDays(Carbon::today()->min($do ?? Carbon::today()), false) + 1);
        $celkemDni = $do ? (int) $od->diffInDays($do) + 1 : null;

        $kDispozici = max(0, $limit + $prijem - $rezerva);
        $rozdeleni = $this->rozdeleni->rozdel($polozky, $kDispozici, $mena, $celkemDni, $uteklo);

        $sLimity = collect($rozdeleni['rows'])->map(fn (array $r) => $r + ['limit' => $r['planned']]);

        // Bezpečná částka počítá s tím, co je opravdu k dispozici. Kdyby počítala jen
        // se stropem, přišlá výplata by ležela stranou a rozpočet by dál doporučoval
        // šetřit — přesně to „samo se to nepřepočítá", kvůli kterému lidi rozpočty vzdají.
        $bezpecne = $this->finance->safeDaily($limit + $prijem, $ciste, $rezerva, $od, $do);

        // Odhad konce podle dosavadního tempa. Jen když je z čeho — pár dnů měsíce
        // nestačí na to, aby se z nich dal odvodit celý.
        $tempo = $ciste / $uteklo;
        $odhad = $celkemDni !== null && $uteklo >= 3 ? round($tempo * $celkemDni, 2) : null;

        $hranice = array_map('intval', array_filter(explode(',', $b->alert_thresholds ?? '80,90,100')));

        // Čerpání se měří proti tomu, co je k dispozici, ne proti původnímu stropu —
        // jinak by rozpočet hlásil překročení i po příjmu, který přesah pokryl.
        $kMereni = $limit + $prijem;
        $procenta = $kMereni > 0 ? (int) round($ciste / $kMereni * 100) : 0;

        return [
            'uuid' => $b->uuid,
            'name' => $b->name,
            'kind' => $b->budget_kind,
            'currency' => $mena,
            'trip' => $cesta ? ['uuid' => $cesta->uuid, 'name' => $cesta->name] : null,
            'starts_on' => $od->toDateString(),
            'ends_on' => $do?->toDateString(),
            'limit' => round($limit, 2),
            'reserve' => round($rezerva, 2),
            'spent' => round($ciste, 2),
            'refunded' => round($vraceno, 2),
            'remaining' => round($kMereni - $ciste, 2),
            'percent' => min(999, $procenta),
            'safe_daily' => $bezpecne,
            'projected_total' => $odhad,
            'projected_verdict' => $odhad === null ? 'unknown' : ($odhad > $limit ? 'over' : ($odhad > $limit * 0.95 ? 'tight' : 'ok')),
            'categories' => $sLimity,
            'allocation' => $rozdeleni,
            // Kategorie, ve kterých se utrácí, ale nikdo na ně nic nevyhradil. Bez nich
            // by tabulka tvrdila, že plán sedí, zatímco peníze odtékají mimo něj.
            'unplanned' => collect($rozpad)
                ->reject(fn (array $k) => $k['category_id'] === null || $limity->has($k['category_id']))
                ->map(fn (array $k) => [
                    'category_uuid' => $k['category_uuid'],
                    'name' => $k['name'],
                    'color' => $k['color'],
                    'spent' => round((float) $k['amount'], 2),
                    'suggested' => $celkemDni !== null && $uteklo >= 3
                        ? round((float) $k['amount'] / $uteklo * $celkemDni, 2)
                        : round((float) $k['amount'], 2),
                    'currency' => $mena,
                ])->values(),
            'income' => round($prijem, 2),
            'income_adds' => (bool) $b->income_adds,
            'available' => round($limit + $prijem, 2),
            'top_categories' => array_slice($rozpad, 0, 6),
            'alert' => $this->hranice($procenta, $hranice),
            'alert_thresholds' => implode(',', $hranice),
            'is_current' => $do === null || Carbon::today()->betweenIncluded($od, $do),
            'owner_user_id' => $b->owner_user_id,
            'owner_name' => $b->owner_user_id ? optional(\App\Models\User::find($b->owner_user_id))->name : null,
            'access' => FinanceAccess::sdileniPro('budget', $b->id),
            'can_edit' => $ja === null || FinanceAccess::smiUpravit('budget', $b->id, $b->owner_user_id, $ja),
        ];
    }

    /** Nejvyšší překročená hranice, nebo null. */
    private function hranice(int $procenta, array $hranice): ?int
    {
        rsort($hranice);

        foreach ($hranice as $h) {
            if ($procenta >= $h) return $h;
        }

        return null;
    }

    /**
     * Rozpočet podle uuid — a když je zadaný uživatel, ověří i právo do něj zapsat.
     *
     * Kontrola je tady, ne v každé akci zvlášť: úprava, mazání i limity procházejí
     * touhle jednou cestou a přidání další akce tím dostane kontrolu automaticky.
     */
    private function rozpocet(GallerySpace $space, string $uuid, ?int $ja = null): Budget
    {
        $rozpocet = Budget::where('gallery_space_id', $space->id)
            ->where('scope', 'ledger')->where('uuid', $uuid)->firstOrFail();

        if ($ja !== null) {
            abort_unless(FinanceAccess::smiUpravit('budget', $rozpocet->id, $rozpocet->owner_user_id, $ja), 403,
                'Do tohohle rozpočtu se smíte dívat, ale ne v něm měnit. Majitel vám může dát právo úprav.');
        }

        return $rozpocet;
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }
}
