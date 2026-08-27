<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Podíl jednoho partnera na jedné transakci.
 *
 * Ukládá se výsledek, ne předpis. Způsobů dělení je šest — rovným dílem, procentem,
 * pevnou částkou, jen někomu, podle osob, podle dnů — a všechny končí u téhož: u seznamu
 * partnerů s částkami. Předpis se dá spočítat znovu a vyjde jinak, kdežto částka, na
 * které se lidé dohodli, se měnit nemá. `basis` zůstává jen jako vysvětlení v přehledu.
 */
class TransactionShare extends Model
{
    protected $fillable = ['transaction_id', 'partner_id', 'amount', 'currency', 'basis', 'basis_value'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'basis_value' => 'decimal:4'];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
