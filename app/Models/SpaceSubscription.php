<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceSubscription extends Model
{
    /*
     * Období a poslední platba patří do `$fillable`. Bez nich je `updateOrCreate`
     * v `EntitlementService` i `update(['last_payment_id' => …])` potichu
     * zahodily: přehled neukázal konec zaplaceného období a tržby počítaly
     * roční předplatné jako měsíční (sloupec měl výchozí `monthly`).
     */
    protected $fillable = [
        'gallery_space_id', 'billing_plan_id', 'status', 'started_at', 'ends_at', 'granted_by', 'note',
        'billing_period', 'current_period_ends_at', 'last_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime', 'last_payment_id' => 'integer',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function space()
    {
        return $this->belongsTo(GallerySpace::class, 'gallery_space_id');
    }
}
