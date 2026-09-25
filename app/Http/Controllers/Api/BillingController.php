<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\Feature;
use App\Models\GallerySpace;
use App\Models\SpaceModule;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class BillingController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** What this space currently has, plus what is on offer. */
    public function overview(Request $request): JsonResponse
    {
        $space = $this->space($request);

        return response()->json($this->entitlements->overview($space) + [
            'trial_available' => ! $this->entitlements->trialUsed($space),
            'trial_days' => EntitlementService::TRIAL_DAYS,
        ]);
    }

    /**
     * Starts the free fortnight.
     *
     * Deliberately not part of checkout: a trial takes no card and creates no payment, and
     * routing it through the gateway would mean holding details we have no reason to hold.
     */
    public function startTrial(Request $request): JsonResponse
    {
        $space = $this->spravovanyProstor($request);
        $user = $request->user();

        $data = $request->validate(['plan' => 'required|string|max:60']);
        $plan = BillingPlan::where('code', $data['plan'])->where('is_public', true)->firstOrFail();

        $subscription = $this->entitlements->startTrial($space, $plan, $user);

        return response()->json([
            'plan' => $plan->code,
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ]);
    }

    /** Public catalogue for the pricing page and the landing page. */
    public function catalogue(): JsonResponse
    {
        if (! Schema::hasTable('billing_plans')) {
            return response()->json(['plans' => [], 'modules' => [], 'features' => []]);
        }

        return response()->json([
            'plans' => BillingPlan::with('grantedFeatures')->where('is_public', true)->orderBy('sort_order')->get()
                ->map(fn (BillingPlan $plan) => $this->entitlements->planPayload($plan))->values(),
            'modules' => BillingModule::with('grantedFeatures')->where('is_public', true)->orderBy('sort_order')->get()
                ->map(fn (BillingModule $module) => $this->entitlements->modulePayload($module) + [
                    'features' => $module->grantedFeatures->pluck('code')->values(),
                ])->values(),
            // Lets the landing page describe a plan by what it actually unlocks.
            // Ordered by sort_order alone: the catalogue is authored core-first, and
            // ordering by category name would lead with "Doplňky" and bury "Základ".
            'features' => Schema::hasTable('features')
                ? Feature::orderBy('sort_order')->get()->map(fn (Feature $feature) => [
                    'code' => $feature->code, 'name' => $feature->name, 'tagline' => $feature->tagline,
                    'category' => $feature->category, 'icon' => $feature->icon, 'is_core' => $feature->is_core,
                ])->values()
                : [],
        ]);
    }

    /**
     * The customer switching one of their entitled features on or off.
     *
     * Volba platí pro celou galerii, takže ji mění dvojice (vlastník a role
     * `PristupDoGalerie::ROLE_DVOJICE`), ne host. Nejen správci: stránka
     * funkcí je v nastavení pro oba z dvojice a volba nic neodemyká ani
     * nestojí peníze — jen skrývá, co už tarif dává.
     */
    public function setFeature(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $space = $this->space($request);
        abort_unless($this->entitlements->patriKDvojici($request->user(), $space), 403,
            'Funkce galerie může zapínat a vypínat jen dvojice, ne host.');
        $feature = Feature::where('code', $code)->firstOrFail();

        $this->entitlements->setFeaturePreference($space, $feature, $data['enabled']);

        return response()->json($this->entitlements->overview($space));
    }

    /**
     * Zapne nebo vypne modul prostoru.
     *
     * Placený modul se kupuje přes bránu (`CheckoutService`); bez platby ho
     * zapne jen provozovatel. Správce prostoru smí zapnout modul zdarma, vypnout
     * kterýkoli a znovu zapnout ten, který má ještě zaplacený — vypnutím se
     * zaplacené období neztrácí.
     */
    public function setModule(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'gallery_space_id' => 'nullable|integer']);
        $space = $this->spravovanyProstor($request, $data['gallery_space_id'] ?? null);
        $module = BillingModule::where('code', $code)->firstOrFail();
        $user = $request->user();

        if ($data['enabled']) {
            // Provozovatel modul přiděluje natrvalo; ostatní jen obnoví, co mají zaplacené.
            $zaplaceny = $user->isOperator() ? null : $this->zaplacenyModul($space, $module);

            abort_if((int) ($module->price_monthly ?? 0) > 0 && $zaplaceny === null && ! $user->isOperator(), 422,
                'Placený modul se kupuje přes platbu v Předplatném. Bez platby ho může zapnout jen provozovatel.');

            // Obnovený modul si nechá své období — roční se nesmí přepsat na měsíční.
            $this->entitlements->enableModule($space, $module, $user,
                $zaplaceny->billing_period ?? 'monthly', $zaplaceny?->ends_at);
        } else {
            $this->entitlements->disableModule($space, $module);
        }

        AuditLog::record('billing.module.'.($data['enabled'] ? 'enabled' : 'disabled'), $module, [
            'gallery_space_id' => $space->id, 'module' => $module->code,
        ]);

        return response()->json($this->entitlements->overview($space));
    }

    /**
     * Přidělí prostoru tarif.
     *
     * Stejně jako `Galerie\AdminController::plan`: zdarma je zdarma, placený
     * tarif se kupuje. Přidělit ho bez platby může jen provozovatel — dřív to
     * šlo každému s `users.role = owner`, a tu dostane každý zaregistrovaný
     * zákazník.
     */
    public function setPlan(Request $request): JsonResponse
    {
        $data = $request->validate(['plan_code' => 'required|string|max:40', 'gallery_space_id' => 'nullable|integer']);
        $space = $this->spravovanyProstor($request, $data['gallery_space_id'] ?? null);
        $plan = BillingPlan::where('code', $data['plan_code'])->firstOrFail();

        $placeny = (int) ($plan->price_monthly ?? 0) > 0 || (int) ($plan->price_yearly ?? 0) > 0;
        abort_if($placeny && ! $request->user()->isOperator(), 422,
            'Placený tarif se kupuje přes platbu v Předplatném. Bez platby ho může přidělit jen provozovatel.');

        $this->entitlements->assignPlan($space, $plan, $request->user());
        AuditLog::record('billing.plan.assigned', $plan, ['gallery_space_id' => $space->id, 'plan' => $plan->code]);

        return response()->json($this->entitlements->overview($space));
    }

    /**
     * Prostor, jehož předplatné volající smí měnit.
     *
     * Rozhoduje role **v tomhle prostoru** (vlastník prostoru nebo členství
     * owner/admin), nikdy `users.role` — tu má `owner` každý, kdo se sám
     * zaregistruje. Cizí prostor přes `gallery_space_id` se hledá jen mezi
     * prostory, kde je volající členem (jinak 404); host dostane 403.
     */
    private function spravovanyProstor(Request $request, ?int $id = null): GallerySpace
    {
        $space = $this->space($request, $id);

        abort_unless($this->entitlements->spravujePredplatne($request->user(), $space), 403,
            'Předplatné může měnit jen vlastník nebo správce prostoru.');

        return $space;
    }

    /**
     * Modul se zaplaceným obdobím, které ještě neuplynulo (i vypnutý).
     *
     * Vypnutí nechává `ends_at` placeného modulu na místě, aby šel znovu
     * zapnout bez nové platby — zákazník si ten čas už koupil.
     */
    private function zaplacenyModul(GallerySpace $space, BillingModule $module): ?SpaceModule
    {
        return SpaceModule::where('gallery_space_id', $space->id)
            ->where('billing_module_id', $module->id)
            ->where('ends_at', '>', now())
            ->first();
    }

    /**
     * Prostor volajícího — ve stejném pořadí jako `PristupDoGalerie::proc`.
     *
     * `gallerySpaces()` řadí výchozí prostor napřed a pak podle id. Dřív tu
     * bylo jen `orderByDesc('is_default')` bez dalšího klíče, a výchozí je
     * každý prostor založený registrací: účet ve dvou prostorech mohl mít
     * předplatné ukázané z jednoho a přístup posouzený v druhém.
     */
    private function space(Request $request, ?int $id = null): GallerySpace
    {
        $query = $request->user()->gallerySpaces();

        $space = $id ? $query->where('gallery_spaces.id', $id)->first() : $query->first();
        abort_if($space === null, 404, 'Prostor nebyl nalezen.');

        return $space;
    }
}
