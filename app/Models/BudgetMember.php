<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Účastník rozpočtu i s příjmem — kvůli poměrnému dělení.
 *
 * Rozpočet zná jeden příjem za celek (`monthly_income`), který slouží k plánu. Na poměr
 * mezi dvěma lidmi to nestačí: plán říká „kolik nám chodí", dělení potřebuje „komu kolik".
 */
class BudgetMember extends Model
{
    protected $fillable = ['budget_id', 'user_id', 'monthly_income', 'currency'];

    protected function casts(): array
    {
        return ['monthly_income' => 'decimal:2'];
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
