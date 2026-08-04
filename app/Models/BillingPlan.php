<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $fillable = ['code', 'name', 'tagline', 'description', 'price_monthly', 'currency', 'member_limit', 'storage_limit_mb', 'features', 'is_public', 'is_default', 'sort_order'];
    protected function casts(): array { return ['features' => 'array', 'is_public' => 'boolean', 'is_default' => 'boolean']; }
    public function subscriptions() { return $this->hasMany(SpaceSubscription::class); }
}
