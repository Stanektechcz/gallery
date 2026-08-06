<?php

namespace App\Services\Billing;

use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\Feature;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\SpaceFeature;
use App\Models\SpaceModule;
use App\Models\SpaceSubscription;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\Schema;

/**
 * The single authority on what a space may use.
 *
 * Three layers, deliberately kept apart:
 *
 *   entitled  — the plan grants it, an add-on grants it, or it is a core feature
 *   enabled   — the customer wants it visible (a preference, never an unlock)
 *   active    — entitled AND enabled; this is what the app gates on
 *
 * A preference can only ever hide something already granted. That keeps "let the customer
 * choose their features" and "let us decide what each plan contains" from fighting.
 */
class EntitlementService
{
    /** Codes referenced by route middleware and the UI. */
    public const FEATURE_BURPS = 'burps';
    public const FEATURE_VOICE_NOTES = 'voice_notes';

    /** @deprecated Kept so older call sites keep working; prefer the FEATURE_ constants. */
    public const MODULE_BURPS = self::FEATURE_BURPS;
    public const MODULE_VOICE_NOTES = self::FEATURE_VOICE_NOTES;

    /*
     | Resolving one feature means reading the catalogue, the plan and the add-ons. The
     | navigation asks about every feature on every page load, which turned into 130
     | queries per request before these caches. They live for one request and are cleared
     | whenever an entitlement changes.
     */

    /** @var array<int, list<string>> */
    private array $entitledCache = [];

    /** @var \Illuminate\Support\Collection<string, Feature>|null */
    private $featureCache = null;

    /** @var array<int, array<int, bool>> */
    private array $preferenceCache = [];

    /** @var array<int, BillingPlan|null> */
    private array $planCache = [];

    /** @var array<string, bool> */
    private array $tableCache = [];

    /** Schema::hasTable queries the database every time; the answer cannot change mid-request. */
    private function hasTable(string $table): bool
    {
        return $this->tableCache[$table] ??= Schema::hasTable($table);
    }

    public function available(): bool
    {
        return $this->hasTable('features') && $this->hasTable('billing_plans');
    }

    /** Called after anything that changes what a space may use. */
    public function forget(?GallerySpace $space = null): void
    {
        if ($space) {
            unset($this->entitledCache[$space->id], $this->preferenceCache[$space->id], $this->planCache[$space->id]);

            return;
        }

        $this->entitledCache = [];
        $this->preferenceCache = [];
        $this->planCache = [];
        $this->featureCache = null;
    }

    /** The whole catalogue, keyed by code. Small enough to hold, and read constantly. */
    private function features()
    {
        return $this->featureCache ??= Feature::orderBy('sort_order')->get()->keyBy('code');
    }

    // ─── Entitlement ────────────────────────────────────────────────

    /**
     * Feature codes the space is entitled to, before the customer's own preferences.
     *
     * @return list<string>
     */
    public function entitledFeatures(GallerySpace $space): array
    {
        if (isset($this->entitledCache[$space->id])) return $this->entitledCache[$space->id];
        if (! $this->available()) return [];

        return $this->entitledCache[$space->id] = (function () use ($space): array {
            $codes = $this->features()->where('is_core', true)->keys()->all();

            $plan = $this->plan($space);
            if ($plan) {
                $codes = [...$codes, ...$plan->grantedFeatures()->pluck('code')->all()];
            }

            // Eager-loaded, so the add-ons cost one query rather than one apiece.
            foreach ($this->activeModules($space) as $module) {
                $codes = [...$codes, ...$module->grantedFeatures->pluck('code')->all()];
            }

            return array_values(array_unique($codes));
        })();
    }

    public function isEntitled(GallerySpace $space, string $featureCode): bool
    {
        return in_array($featureCode, $this->entitledFeatures($space), true);
    }

    /**
     * What the app actually gates on: granted by the plan and not switched off by the
     * customer. Core features ignore the preference entirely.
     */
    public function hasFeature(GallerySpace $space, string $featureCode): bool
    {
        if (! $this->isEntitled($space, $featureCode)) return false;

        $feature = $this->features()->get($featureCode);
        if (! $feature || $feature->is_core || ! $feature->is_optional) return true;

        // No row means the customer has not opted out: a granted feature starts on.
        return $this->preferences($space)[$feature->id] ?? true;
    }

    /** Backwards-compatible alias; module codes and feature codes share a namespace. */
    public function hasModule(GallerySpace $space, string $code): bool
    {
        return $this->hasFeature($space, $code);
    }

    /** The customer's choice. Refuses to enable something they are not entitled to. */
    public function setFeaturePreference(GallerySpace $space, Feature $feature, bool $enabled): void
    {
        abort_if($enabled && ! $this->isEntitled($space, $feature->code), 402, 'Tuhle funkci váš tarif neobsahuje.');
        abort_if(! $enabled && ($feature->is_core || ! $feature->is_optional), 422, 'Základní funkci nelze vypnout.');

        SpaceFeature::updateOrCreate(
            ['gallery_space_id' => $space->id, 'feature_id' => $feature->id],
            ['enabled' => $enabled]
        );

        $this->forget($space);
    }

    // ─── Plan and modules ───────────────────────────────────────────

    /** @return array<int, bool> Feature id => wanted, for one space. */
    private function preferences(GallerySpace $space): array
    {
        return $this->preferenceCache[$space->id] ??= SpaceFeature::where('gallery_space_id', $space->id)
            ->pluck('enabled', 'feature_id')
            ->map(fn ($enabled) => (bool) $enabled)
            ->all();
    }

    public function plan(GallerySpace $space): ?BillingPlan
    {
        if (! $this->hasTable('space_subscriptions')) return null;
        if (array_key_exists($space->id, $this->planCache)) return $this->planCache[$space->id];

        $subscription = SpaceSubscription::with('plan')
            ->where('gallery_space_id', $space->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first();

        return $this->planCache[$space->id] = $subscription?->plan ?? BillingPlan::where('is_default', true)->first();
    }

    public function subscription(GallerySpace $space): ?SpaceSubscription
    {
        if (! $this->hasTable('space_subscriptions')) return null;

        return SpaceSubscription::with('plan')->where('gallery_space_id', $space->id)->first();
    }

    /** @return \Illuminate\Support\Collection<int, BillingModule> */
    public function activeModules(GallerySpace $space)
    {
        if (! $this->hasTable('space_modules')) return collect();

        $ids = SpaceModule::where('gallery_space_id', $space->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('billing_module_id');

        // Eager-loaded so entitledFeatures() does not query per add-on.
        return BillingModule::with('grantedFeatures')->whereIn('id', $ids)->get();
    }

    public function enableModule(GallerySpace $space, BillingModule $module, ?User $actor = null, string $period = 'monthly', ?\DateTimeInterface $until = null): SpaceModule
    {
        $this->forget($space);

        return SpaceModule::updateOrCreate(
            ['gallery_space_id' => $space->id, 'billing_module_id' => $module->id],
            [
                'status' => 'active', 'activated_at' => now(), 'ends_at' => null,
                'billing_period' => $period, 'current_period_ends_at' => $until,
                'granted_by' => $actor?->id,
            ]
        );
    }

    public function disableModule(GallerySpace $space, BillingModule $module): void
    {
        SpaceModule::where('gallery_space_id', $space->id)
            ->where('billing_module_id', $module->id)
            ->update(['status' => 'paused', 'ends_at' => now()]);

        $this->forget($space);
    }

    public function assignPlan(GallerySpace $space, BillingPlan $plan, ?User $actor = null, string $period = 'monthly', ?\DateTimeInterface $until = null): SpaceSubscription
    {
        $this->forget($space);

        return SpaceSubscription::updateOrCreate(
            ['gallery_space_id' => $space->id],
            [
                'billing_plan_id' => $plan->id, 'status' => 'active',
                'started_at' => now(), 'ends_at' => null,
                'billing_period' => $period, 'current_period_ends_at' => $until,
                'granted_by' => $actor?->id,
            ]
        );
    }

    // ─── Limits ─────────────────────────────────────────────────────

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

    public function canStore(GallerySpace $space, int $bytes): bool
    {
        $usage = $this->storageUsage($space);

        return $usage['limit_bytes'] === null || $usage['used_bytes'] + $bytes <= $usage['limit_bytes'];
    }

    // ─── Payloads ───────────────────────────────────────────────────

    /** Everything the customer's own subscription screen needs. */
    public function overview(GallerySpace $space): array
    {
        if (! $this->available()) {
            return ['available' => false, 'plan' => null, 'modules' => [], 'features' => [], 'active_features' => []];
        }

        $entitled = $this->entitledFeatures($space);
        $preferences = SpaceFeature::where('gallery_space_id', $space->id)->get()->keyBy('feature_id');
        $subscription = $this->subscription($space);

        $features = Feature::orderBy('sort_order')->orderBy('name')->get()->map(function (Feature $feature) use ($entitled, $preferences) {
            $isEntitled = $feature->is_core || in_array($feature->code, $entitled, true);
            $preference = $preferences->get($feature->id);
            $enabled = $feature->is_core || ! $feature->is_optional || $preference === null || $preference->enabled;

            return [
                'code' => $feature->code, 'name' => $feature->name, 'tagline' => $feature->tagline,
                'description' => $feature->description, 'category' => $feature->category,
                'icon' => $feature->icon, 'route' => $feature->route,
                'is_core' => $feature->is_core, 'can_switch_off' => $feature->is_optional && ! $feature->is_core,
                'entitled' => $isEntitled,
                'enabled' => $isEntitled && $enabled,
            ];
        })->values()->all();

        $modules = BillingModule::with('grantedFeatures')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (BillingModule $module) => $this->modulePayload($module) + [
                'is_active' => $this->activeModules($space)->contains('id', $module->id),
                'features' => $module->grantedFeatures->pluck('code')->all(),
            ])->values()->all();

        return [
            'available' => true,
            'plan' => ($plan = $this->plan($space)) ? $this->planPayload($plan) : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'billing_period' => $subscription->billing_period,
                'current_period_ends_at' => optional($subscription->current_period_ends_at)->toIso8601String(),
            ] : null,
            'modules' => $modules,
            'features' => $features,
            'active_features' => collect($features)->filter(fn (array $f) => $f['enabled'])->pluck('code')->values()->all(),
            'usage' => ['members' => $this->memberUsage($space), 'storage' => $this->storageUsage($space)],
        ];
    }

    public function planPayload(BillingPlan $plan): array
    {
        return [
            'code' => $plan->code, 'group_type' => $plan->group_type, 'name' => $plan->name,
            'tagline' => $plan->tagline, 'description' => $plan->description,
            'price_monthly' => $plan->price_monthly, 'price_yearly' => $plan->price_yearly,
            'currency' => $plan->currency,
            'member_limit' => $plan->member_limit, 'storage_limit_mb' => $plan->storage_limit_mb,
            'features' => $plan->features ?? [],
            'feature_codes' => $plan->relationLoaded('grantedFeatures')
                ? $plan->grantedFeatures->pluck('code')->all()
                : $plan->grantedFeatures()->pluck('code')->all(),
            'is_default' => $plan->is_default, 'highlight' => $plan->highlight,
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

    private function featureByCode(string $code): ?Feature
    {
        static $cache = [];
        if (! array_key_exists($code, $cache)) {
            $cache[$code] = Feature::where('code', $code)->first();
        }

        return $cache[$code];
    }
}
