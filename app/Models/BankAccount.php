<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BankAccount extends Model
{
    protected $fillable = ['uuid', 'bank_connection_id', 'external_id_hash', 'encrypted_external_id', 'name', 'owner_name',
        'iban_last4', 'currency', 'account_type', 'is_joint', 'is_enabled', 'current_balance', 'available_balance',
        'balance_updated_at', 'history_available_from'];

    protected function casts(): array
    {
        return ['encrypted_external_id' => 'encrypted', 'owner_name' => 'encrypted', 'is_joint' => 'boolean', 'is_enabled' => 'boolean', 'balance_updated_at' => 'datetime', 'history_available_from' => 'date', 'current_balance' => 'decimal:4', 'available_balance' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function connection()
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    public function transactions()
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function snapshots()
    {
        return $this->hasMany(BankBalanceSnapshot::class);
    }
}
