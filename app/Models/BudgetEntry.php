<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BudgetEntry extends Model
{
    protected $fillable = [
        'uuid', 'budget_id', 'budget_category_id', 'user_id',
        'kind', 'amount', 'currency', 'spent_on', 'note', 'is_recurring',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:2',
            'is_recurring' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->uuid ??= (string) Str::uuid());
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function category()
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
