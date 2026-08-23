<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Rozpočet na období — osobní, nebo společný pro celý prostor.
 */
class Budget extends Model
{
    use SoftDeletes;
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'owner_user_id', 'name', 'currency',
        'starts_on', 'ends_on', 'monthly_income', 'note', 'is_shared', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'monthly_income' => 'decimal:2',
            'is_shared' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $budget) => $budget->uuid ??= (string) Str::uuid());
    }

    public function categories()
    {
        return $this->hasMany(BudgetCategory::class)->orderBy('sort_order');
    }

    public function entries()
    {
        return $this->hasMany(BudgetEntry::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Kdo na rozpočet vidí.
     *
     * Osobní rozpočet je soukromý, dokud ho vlastník nesdílí. Půlroční pobyt v cizině je
     * dost osobní věc na to, aby se do něj nekoukalo automaticky.
     */
    public function isVisibleTo(User $user): bool
    {
        return $this->owner_user_id === null
            || $this->is_shared
            || $this->owner_user_id === $user->id;
    }

    /** Kolik celých měsíců období pokrývá; nedokončený měsíc se počítá celý. */
    public function monthsCovered(): int
    {
        $konec = $this->ends_on ?? now();

        return max(1, (int) ceil($this->starts_on->diffInDays($konec) / 30.44));
    }
}
