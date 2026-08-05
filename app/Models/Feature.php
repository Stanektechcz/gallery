<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $fillable = ['code', 'name', 'tagline', 'description', 'category', 'icon', 'route', 'is_core', 'is_optional', 'sort_order'];

    protected function casts(): array
    {
        return ['is_core' => 'boolean', 'is_optional' => 'boolean'];
    }

    public function plans()
    {
        return $this->belongsToMany(BillingPlan::class, 'billing_plan_feature');
    }

    public function modules()
    {
        return $this->belongsToMany(BillingModule::class, 'billing_module_feature');
    }
}
