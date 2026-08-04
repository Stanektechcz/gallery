<?php

namespace App\Services\Billing;

use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\SpaceModule;
use App\Models\SpaceSubscription;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\Schema;

/**
 * Single place that answers "may this space use X?". Billing is not wired up yet, so an
 * entitlement comes from an administrator switching a module on; once payments exist,
 * only the writes below change, not the callers.
 */
class EntitlementService
{
    /** Modules that ship as paid add-ons. Referenced by route middleware and the UI. */
    public const MODULE_BURPS = 'burps';
    public const MODULE_VOICE_NOTES = 'voice_notes';

    public function available(): bool
    {
        return Schema::hasTable('billing_modules') && Schema::hasTable('space_modules');
    }

    /** Modules included in every plan, needing no purchase. */
    private function alwaysIncluded(): array
    {
        return [self::MODULE_VOICE_NOTES];
    }

    public function hasModule(GallerySpace $space, string $moduleCode): bool
    {
        if (in_array($moduleCode, $this->alwaysIncluded(), true)) return true;
        if (! $this->available()) return false;

        $module = BillingModule::where('code', $moduleCode)->first();
        if (! $module) return false;

        return SpaceModule::where('gallery_space_id', $space->id)
            ->where('billing_module_id', $module->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->exists();
    }

    /** The space's current plan, falling back to the plan flagged as default. */
    public function plan(GallerySpace $space): ?BillingPlan
    {
        if (! Schema::hasTable('space_subscriptions')) return null;

        $subscription = SpaceSubscription::with('plan')
            ->where('gallery_space_id', $space->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first();

        return $subscription?->plan ?? BillingPlan::where('is_default', true)->first();
    }

    /** Everything the frontend needs to show what is on and what is for sale. */
    public function overview(GallerySpace $space): array
    {
        if (! $this->available()) {
            return ['available' => false, 'plan' => null, 'modules' => [], 'active_modules' => $this->alwaysIncluded()];
        }

        $plan = $this->plan($space);
        $modules = BillingModule::orderBy('sort_order')->orderBy('name')->get();
        $active = [];
        foreach ($modules as $module) {
            if ($this->hasModule($space, $module->code)) $active[] = $module->code;
        }

        return [
            'available' => true,
            'plan' => $plan ? $this->planPayload($plan) : null,
            'modules' => $modules->map(fn (BillingModule $module) => $this->modulePayload($module) + [
                'is_active' => in_array($module->code, $active, true),
                'is_included' => in_array($module->code, $this->alwaysIncluded(), true),
            ])->values()->all(),
            'active_modules' => array_values(array_unique([...$active, ...$this->alwaysIncluded()])),
            'usage' => ['members' => $this->memberUsage($space), 'storage' => $this->storageUsage($space)],
        ];
    }

    /** Members currently in the space, and whether the plan allows one more. */
    public function memberUsage(GallerySpace $space): array
    {
        $used = $space->members()->count();
        $limit = $this->plan($space)?->member_limit;

        return ['used' => $used, 'limit' => $limit, 'can_add' => $limit === null || $used < $limit];
    }

    public function canAddMember(GallerySpace $space): bool
    {
        return $this->memberUsage($space)['can_add'];
    }

    /**
     * Storage the space occupies, against its plan. The global space scope is lifted
     * because this sums one named space, which may not be the caller's own.
     */
    public function storageUsage(GallerySpace $space): array
    {
        $usedBytes = (int) MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->sum('size_bytes');

        $limitMb = $this->plan($space)?->storage_limit_mb;
        $limitBytes = $limitMb === null ? null : $limitMb * 1024 * 1024;

        return [
            'used_bytes' => $usedBytes,
            'limit_bytes' => $limitBytes,
            'limit_mb' => $limitMb,
            'remaining_bytes' => $limitBytes === null ? null : max(0, $limitBytes - $usedBytes),
            'percent' => $limitBytes ? min(100, (int) round($usedBytes / $limitBytes * 100)) : null,
        ];
    }

    /** True when the space can still take a file of this size. */
    public function canStore(GallerySpace $space, int $bytes): bool
    {
        $usage = $this->storageUsage($space);

        return $usage['limit_bytes'] === null || $usage['used_bytes'] + $bytes <= $usage['limit_bytes'];
    }

    public function enableModule(GallerySpace $space, BillingModule $module, ?User $actor = null): SpaceModule
    {
        return SpaceModule::updateOrCreate(
            ['gallery_space_id' => $space->id, 'billing_module_id' => $module->id],
            ['status' => 'active', 'activated_at' => now(), 'ends_at' => null, 'granted_by' => $actor?->id]
        );
    }

    public function disableModule(GallerySpace $space, BillingModule $module): void
    {
        SpaceModule::where('gallery_space_id', $space->id)
            ->where('billing_module_id', $module->id)
            ->update(['status' => 'paused', 'ends_at' => now()]);
    }

    public function assignPlan(GallerySpace $space, BillingPlan $plan, ?User $actor = null): SpaceSubscription
    {
        return SpaceSubscription::updateOrCreate(
            ['gallery_space_id' => $space->id],
            ['billing_plan_id' => $plan->id, 'status' => 'active', 'started_at' => now(), 'ends_at' => null, 'granted_by' => $actor?->id]
        );
    }

    public function planPayload(BillingPlan $plan): array
    {
        return [
            'code' => $plan->code, 'name' => $plan->name, 'tagline' => $plan->tagline, 'description' => $plan->description,
            'price_monthly' => $plan->price_monthly, 'currency' => $plan->currency,
            'member_limit' => $plan->member_limit, 'storage_limit_mb' => $plan->storage_limit_mb,
            'features' => $plan->features ?? [], 'is_default' => $plan->is_default,
        ];
    }

    public function modulePayload(BillingModule $module): array
    {
        return [
            'code' => $module->code, 'name' => $module->name, 'tagline' => $module->tagline,
            'description' => $module->description, 'price_monthly' => $module->price_monthly,
            'currency' => $module->currency, 'icon' => $module->icon,
        ];
    }
}
