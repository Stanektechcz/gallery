<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $fillable = [
        'code', 'group_type', 'name', 'tagline', 'description', 'price_monthly', 'price_yearly', 'currency',
        'member_limit', 'storage_limit_mb', 'features', 'is_public', 'is_default', 'highlight', 'sort_order',
    ];

    protected function casts(): array
    {
        // `features` is the marketing bullet list; grantedFeatures() below is the real entitlement.
        return ['features' => 'array', 'is_public' => 'boolean', 'is_default' => 'boolean', 'highlight' => 'boolean'];
    }

    public function subscriptions() { return $this->hasMany(SpaceSubscription::class); }

    /** Features this plan unlocks, editable by the operator. */
    public function grantedFeatures() { return $this->belongsToMany(Feature::class, 'billing_plan_feature'); }
}
