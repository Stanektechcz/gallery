<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingModule extends Model
{
    protected $fillable = ['code', 'name', 'tagline', 'description', 'price_monthly', 'currency', 'icon', 'is_public', 'sort_order'];
    protected function casts(): array { return ['is_public' => 'boolean']; }
    public function spaceModules() { return $this->hasMany(SpaceModule::class); }
}
