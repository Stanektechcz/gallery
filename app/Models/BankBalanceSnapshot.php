<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankBalanceSnapshot extends Model
{
    protected $fillable = ['bank_account_id', 'booked_balance', 'available_balance', 'currency', 'captured_at', 'source', 'snapshot_key'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'booked_balance' => 'decimal:4', 'available_balance' => 'decimal:4'];
    }

    public function account()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
