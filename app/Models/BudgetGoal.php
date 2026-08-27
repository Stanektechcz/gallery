<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jeden cíl spoření uvnitř rozpočtu.
 *
 * Rozpočet měl jedno pole `savings_target`. „Letenky domů za čtyři sta" a „notebook za
 * dvanáct set" jsou ale dva různé cíle s různými termíny a do jednoho čísla se nevejdou.
 *
 * Uspořená částka se zadává ručně. Odvozovat ji z rozdílu příjmů a výdajů by znamenalo
 * tvrdit, že všechno, co zbylo, patří tomuhle cíli — a u dvou cílů naráz by to bylo
 * tvrzení dvakrát.
 */
class BudgetGoal extends Model
{
    protected $fillable = ['budget_id', 'uuid', 'name', 'target_amount', 'currency', 'saved_amount', 'target_on', 'note', 'sort_order'];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'saved_amount' => 'decimal:2',
            'target_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $cil) => $cil->uuid ??= (string) Str::uuid());
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }
}
