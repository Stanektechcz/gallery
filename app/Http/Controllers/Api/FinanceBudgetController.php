<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Services\Finance\FinanceService;
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
    public function __construct(private readonly FinanceService $finance) {}

    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request);

        $rozpocty = Budget::where('gallery_space_id', $space->id)
            ->where('scope', 'ledger')
            ->with('financeProject:id,uuid,name,starts_on,ends_on,base_currency')
            ->orderByDesc('starts_on')
            ->get();

        return response()->json([
            'budgets' => $rozpocty->map(fn (Budget $b) => $this->stav($space, $b))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $data = $this->data($request);

        $rozpocet = Budget::create($data + [
            'gallery_space_id' => $space->id,
            'owner_user_id' => null,
            'scope' => 'ledger',
            'is_shared' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->ulozLimity($request, $space, $rozpocet);

        return response()->json(['budget' => $this->stav($space, $rozpocet->fresh())], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $space = $this->space($request);
        $rozpocet = $this->rozpocet($space, $uuid);

        $rozpocet->update($this->data($request, true));
        $this->ulozLimity($request, $space, $rozpocet);

        return response()->json(['budget' => $this->stav($space, $rozpocet->fresh())]);
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
        $rozpocet = $this->rozpocet($space, $uuid);

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
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
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
    private function stav(GallerySpace $space, Budget $b): array
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
            ->with(['category:id,name,color,icon', 'refundOf:id,category_id'])
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
            ->pluck('amount', 'finance_category_id');

        // Kategorie s limitem se ukazují i tehdy, když se v nich ještě nic neutratilo —
        // jinak by nový limit zmizel a vypadalo by to, že se neuložil.
        $sLimity = FinanceCategory::where('gallery_space_id', $space->id)
            ->whereIn('id', $limity->keys())->get()
            ->map(function (FinanceCategory $k) use ($rozpad, $limity, $mena) {
                $skutecnost = collect($rozpad)->firstWhere('category_id', $k->id);
                $utraceno = (float) ($skutecnost['amount'] ?? 0);
                $limit = (float) $limity[$k->id];

                return [
                    'category_uuid' => $k->uuid,
                    'name' => $k->name,
                    'color' => $k->color,
                    'limit' => round($limit, 2),
                    'spent' => round($utraceno, 2),
                    'remaining' => round($limit - $utraceno, 2),
                    'percent' => $limit > 0 ? min(999, (int) round($utraceno / $limit * 100)) : 0,
                    'currency' => $mena,
                ];
            })
            ->sortByDesc('percent')
            ->values();

        $bezpecne = $this->finance->safeDaily($limit, $ciste, $rezerva, $od, $do);

        // Odhad konce podle dosavadního tempa. Jen když je z čeho — pár dnů měsíce
        // nestačí na to, aby se z nich dal odvodit celý.
        $uteklo = max(1, (int) $od->diffInDays(Carbon::today()->min($do ?? Carbon::today()), false) + 1);
        $tempo = $ciste / $uteklo;
        $celkemDni = $do ? (int) $od->diffInDays($do) + 1 : null;
        $odhad = $celkemDni !== null && $uteklo >= 3 ? round($tempo * $celkemDni, 2) : null;

        $hranice = array_map('intval', array_filter(explode(',', $b->alert_thresholds ?? '80,90,100')));
        $procenta = $limit > 0 ? (int) round($ciste / $limit * 100) : 0;

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
            'remaining' => round($limit - $ciste, 2),
            'percent' => min(999, $procenta),
            'safe_daily' => $bezpecne,
            'projected_total' => $odhad,
            'projected_verdict' => $odhad === null ? 'unknown' : ($odhad > $limit ? 'over' : ($odhad > $limit * 0.95 ? 'tight' : 'ok')),
            'categories' => $sLimity,
            'top_categories' => array_slice($rozpad, 0, 6),
            'alert' => $this->hranice($procenta, $hranice),
            'alert_thresholds' => implode(',', $hranice),
            'is_current' => $do === null || Carbon::today()->betweenIncluded($od, $do),
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

    private function rozpocet(GallerySpace $space, string $uuid): Budget
    {
        return Budget::where('gallery_space_id', $space->id)
            ->where('scope', 'ledger')->where('uuid', $uuid)->firstOrFail();
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }
}
