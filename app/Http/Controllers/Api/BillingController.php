<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\Feature;
use App\Models\GallerySpace;
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
        $space = $this->space($request);
        $user = $request->user();

        abort_unless(in_array($user->role, ['owner', 'admin'], true), 403,
            'Zkušební období může spustit jen správce prostoru.');

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

    /** The customer switching one of their entitled features on or off. */
    public function setFeature(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $space = $this->space($request);
        $feature = Feature::where('code', $code)->firstOrFail();

        $this->entitlements->setFeaturePreference($space, $feature, $data['enabled']);

        return response()->json($this->entitlements->overview($space));
    }

    /**
     * Switches a module on or off for a space. Until a payment provider exists this is
     * the only way an add-on is granted, so it is restricted to administrators.
     */
    public function setModule(Request $request, string $code): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403, 'Moduly může měnit jen správce.');
        $data = $request->validate(['enabled' => 'required|boolean', 'gallery_space_id' => 'nullable|integer']);
        $space = $this->space($request, $data['gallery_space_id'] ?? null);
        $module = BillingModule::where('code', $code)->firstOrFail();

        if ($data['enabled']) {
            $this->entitlements->enableModule($space, $module, $request->user());
        } else {
            $this->entitlements->disableModule($space, $module);
        }

        AuditLog::record('billing.module.' . ($data['enabled'] ? 'enabled' : 'disabled'), $module, [
            'gallery_space_id' => $space->id, 'module' => $module->code,
        ]);

        return response()->json($this->entitlements->overview($space));
    }

    public function setPlan(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403, 'Tarif může měnit jen správce.');
        $data = $request->validate(['plan_code' => 'required|string|max:40', 'gallery_space_id' => 'nullable|integer']);
        $space = $this->space($request, $data['gallery_space_id'] ?? null);
        $plan = BillingPlan::where('code', $data['plan_code'])->firstOrFail();

        $this->entitlements->assignPlan($space, $plan, $request->user());
        AuditLog::record('billing.plan.assigned', $plan, ['gallery_space_id' => $space->id, 'plan' => $plan->code]);

        return response()->json($this->entitlements->overview($space));
    }

    private function space(Request $request, ?int $id = null): GallerySpace
    {
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }
}
