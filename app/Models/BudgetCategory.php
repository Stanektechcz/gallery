<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetCategory extends Model
{
    protected $fillable = ['budget_id', 'name', 'planned_monthly', 'color', 'icon', 'sort_order'];

    protected function casts(): array
    {
        return ['planned_monthly' => 'decimal:2'];
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function entries()
    {
        return $this->hasMany(BudgetEntry::class);
    }
}
