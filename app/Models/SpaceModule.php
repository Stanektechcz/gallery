<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceModule extends Model
{
    /*
     * Období patří do `$fillable`, jinak ho `EntitlementService::enableModule`
     * potichu zahodí. `last_payment_id` tabulka modulů nemá (jen předplatné).
     */
    protected $fillable = [
        'gallery_space_id', 'billing_module_id', 'status', 'activated_at', 'ends_at', 'granted_by',
        'billing_period', 'current_period_ends_at',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'ends_at' => 'datetime', 'current_period_ends_at' => 'datetime'];
    }

    public function module()
    {
        return $this->belongsTo(BillingModule::class, 'billing_module_id');
    }

    public function space()
    {
        return $this->belongsTo(GallerySpace::class, 'gallery_space_id');
    }
}
