<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BankTransaction extends Model
{
    protected $fillable = ['uuid', 'bank_account_id', 'bank_import_id', 'external_id_hash', 'encrypted_external_id', 'status',
        'direction', 'amount', 'currency', 'original_amount', 'original_currency', 'fee_amount', 'balance_after', 'booked_at',
        'value_date', 'merchant_name', 'counterparty_name', 'description', 'bank_transaction_code', 'transaction_type', 'category', 'trip_action',
        'category_is_manual', 'is_internal_transfer', 'is_refund', 'is_fee', 'is_cash_withdrawal', 'provider_payload'];

    protected function casts(): array
    {
        return ['encrypted_external_id' => 'encrypted', 'merchant_name' => 'encrypted', 'counterparty_name' => 'encrypted', 'description' => 'encrypted', 'provider_payload' => 'encrypted:array',
            'booked_at' => 'datetime', 'value_date' => 'date', 'category_is_manual' => 'boolean', 'is_internal_transfer' => 'boolean',
            'is_refund' => 'boolean', 'is_fee' => 'boolean', 'is_cash_withdrawal' => 'boolean', 'amount' => 'decimal:4',
            'original_amount' => 'decimal:4', 'fee_amount' => 'decimal:4', 'balance_after' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
