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
            'plans' => BillingPlan::with('grantedFeatures')->orderBy('sort_order')->get()
                ->map(fn (BillingPlan $plan) => [
                    'code' => $plan->code, 'name' => $plan->name, 'group_type' => $plan->group_type,
                    'price_monthly' => $plan->price_monthly, 'price_yearly' => $plan->price_yearly,
                    'currency' => $plan->currency, 'member_limit' => $plan->member_limit,
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

    private function authorizeOperator(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403, 'Nabídku může měnit jen správce.');
    }
}
