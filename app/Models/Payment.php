<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'purchase_type', 'billing_plan_id', 'billing_module_id',
        'billing_period', 'amount', 'currency', 'gateway', 'reference', 'transaction_id', 'status',
        'method', 'payer_email', 'paid_at', 'gateway_payload',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'gateway_payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $payment->uuid ??= (string) Str::uuid();
            // The gateway reference must be unguessable and unique per attempt.
            $payment->reference ??= 'MG-' . strtoupper(Str::random(16));
        });
    }

    public function plan() { return $this->belongsTo(BillingPlan::class, 'billing_plan_id'); }
    public function module() { return $this->belongsTo(BillingModule::class, 'billing_module_id'); }
    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }

    /** Who paid. Needed by the invoice, which names the customer rather than an id. */
    public function buyer() { return $this->belongsTo(User::class, 'created_by'); }

    public function isPaid(): bool { return $this->status === 'paid'; }
}
