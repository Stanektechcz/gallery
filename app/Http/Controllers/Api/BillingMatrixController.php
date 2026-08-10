<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operator control over the offering: which features belong to which plan, and which to
 * which paid add-on. Editable at runtime so the catalogue can change without a release.
 */
class BillingMatrixController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeOperator($request);

        return response()->json([
            'features' => Feature::orderBy('sort_order')->get()
                ->map(fn (Feature $feature) => [
                    'code' => $feature->code, 'name' => $feature->name, 'category' => $feature->category,
                    'icon' => $feature->icon, 'is_core' => $feature->is_core, 'tagline' => $feature->tagline,
                ])->values(),
            'plans' => BillingPlan::with('grantedFeatures')
                ->withCount(['subscriptions as subscribers' => fn ($query) => $query->where('status', 'active')])
                ->orderBy('sort_order')->get()
                ->map(fn (BillingPlan $plan) => [
                    'code' => $plan->code, 'name' => $plan->name, 'group_type' => $plan->group_type,
                    'price_monthly' => $plan->price_monthly, 'price_yearly' => $plan->price_yearly,
                    'currency' => $plan->currency, 'member_limit' => $plan->member_limit,
                    'storage_limit_mb' => $plan->storage_limit_mb, 'tagline' => $plan->tagline,
                    'is_public' => $plan->is_public, 'is_default' => $plan->is_default,
                    'subscribers' => (int) $plan->subscribers,
                    'feature_codes' => $plan->grantedFeatures->pluck('code')->values(),
                ])->values(),
            'modules' => BillingModule::with('grantedFeatures')->orderBy('sort_order')->get()
                ->map(fn (BillingModule $module) => [
                    'code' => $module->code, 'name' => $module->name, 'icon' => $module->icon,
                    'price_monthly' => $module->price_monthly, 'currency' => $module->currency,
                    'feature_codes' => $module->grantedFeatures->pluck('code')->values(),
                ])->values(),
        ]);
    }

    /** Replaces the feature set of one plan or one module. */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeOperator($request);

        $data = $request->validate([
            'target' => 'required|in:plan,module',
            'code' => 'required|string|max:60',
            'feature_codes' => 'present|array|max:200',
            'feature_codes.*' => 'string|max:60',
        ]);

        $featureIds = Feature::whereIn('code', $data['feature_codes'])->pluck('id')->all();

        DB::transaction(function () use ($data, $featureIds): void {
            if ($data['target'] === 'plan') {
                $plan = BillingPlan::where('code', $data['code'])->firstOrFail();
                $plan->grantedFeatures()->sync($featureIds);
                AuditLog::record('billing.plan.features', $plan, ['plan' => $plan->code, 'features' => $data['feature_codes']]);
            } else {
                $module = BillingModule::where('code', $data['code'])->firstOrFail();
                $module->grantedFeatures()->sync($featureIds);
                AuditLog::record('billing.module.features', $module, ['module' => $module->code, 'features' => $data['feature_codes']]);
            }
        });

        return $this->show($request);
    }

    /**
     * Edits one plan: what it is called, what it costs, what it allows.
     *
     * The catalogue lived in a seeder, which meant every price change was a deployment.
     * Prices here are in hundredths, as everywhere else money appears — a plan stored as
     * 149.00 and charged as 149 hundredths is the kind of mistake that only shows up on
     * somebody's card statement.
     *
     * A price change does not touch anybody already subscribed. Their window was paid at
     * the old price and repricing it underneath them would be taking money they never
     * agreed to; the new figure applies at their next purchase.
     *
     * Existing codes only. Creating a plan means deciding which features it grants and
     * where it sits in the ladder, which is the matrix's job, not a text field's.
     */
    public function updatePlan(Request $request, string $code): JsonResponse
    {
        $this->authorizeOperator($request);

        $plan = BillingPlan::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'tagline' => 'nullable|string|max:200',
            'price_monthly' => 'required|integer|min:0|max:10000000',
            'price_yearly' => 'required|integer|min:0|max:100000000',
            'member_limit' => 'nullable|integer|min:1|max:1000',
            'storage_limit_mb' => 'nullable|integer|min:0',
            'is_public' => 'required|boolean',
        ]);

        // The default plan is what a new space lands on, so it cannot be hidden — that
        // would leave sign-ups pointing at something nobody can see or buy.
        abort_if($plan->is_default && ! $data['is_public'], 422,
            'Výchozí tarif nelze skrýt. Nejdřív určete výchozím jiný.');

        // Lowering the member limit below what a space already uses would put people over
        // quota retroactively; nobody added a member against the rules at the time.
        $largest = (int) DB::table('space_subscriptions')
            ->join('gallery_space_user', 'gallery_space_user.gallery_space_id', '=', 'space_subscriptions.gallery_space_id')
            ->where('space_subscriptions.billing_plan_id', $plan->id)
            ->where('space_subscriptions.status', 'active')
            ->groupBy('space_subscriptions.gallery_space_id')
            ->selectRaw('count(*) as members')
            ->pluck('members')->max();

        abort_if($data['member_limit'] !== null && $largest > $data['member_limit'], 422,
            'Některý prostor s tímto tarifem má ' . $largest . ' členů. Limit nelze snížit pod tento počet.');

        $before = $plan->only(['name', 'price_monthly', 'price_yearly', 'member_limit', 'is_public']);
        $plan->update($data);

        AuditLog::record('billing.plan.updated', $plan, ['plan' => $plan->code, 'before' => $before, 'after' => $data]);

        return $this->show($request);
    }

    private function authorizeOperator(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403, 'Nabídku může měnit jen správce.');
    }
}
