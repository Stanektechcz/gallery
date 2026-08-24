<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Uzávěrka dluhu k danému dni.
 *
 * Neruší položky ani je neoznačuje. Říká jen „k tomuhle datu bylo v téhle měně
 * srovnáno" — a dluh se od té chvíle počítá až z toho, co přišlo potom.
 */
class BudgetSettlement extends Model
{
    protected $fillable = [
        'uuid', 'budget_id', 'currency', 'settled_through',
        'amount', 'from_user_id', 'to_user_id', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'settled_through' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function from()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
