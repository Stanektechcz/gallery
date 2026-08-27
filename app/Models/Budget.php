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
        'savings_target', 'savings_target_on', 'period_unit',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'monthly_income' => 'decimal:2',
            'savings_target' => 'decimal:2',
            'savings_target_on' => 'date',
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

    /** Účastníci i s příjmem. Prázdné je běžný stav — pak se dělí napůl. */
    public function members()
    {
        return $this->hasMany(BudgetMember::class);
    }

    /**
     * Podíl každého člověka na společných výdajích podle příjmu.
     *
     * Vrací null, když se poměr nedá spočítat — chybí příjmy, je vyplněný jen jeden,
     * nebo jsou v různých měnách a přepočítat je nemáme čím. Volající pak dělí napůl,
     * protože odhadovaný poměr je horší než přiznaná polovina.
     *
     * @return array<int, float>|null  id uživatele → podíl 0–1
     */
    public function incomeShares(): ?array
    {
        $this->loadMissing('members');

        $sPrijmem = $this->members->filter(fn (BudgetMember $c) => $c->monthly_income !== null && (float) $c->monthly_income > 0);

        if ($sPrijmem->count() < 2) {
            return null;
        }

        // Různé měny by se musely přepočítat kurzem, který si tenhle systém zásadně
        // nevymýšlí. Raději žádný poměr než poměr postavený na hádaném kurzu.
        if ($sPrijmem->pluck('currency')->map(fn (?string $m) => $m ?? $this->currency)->unique()->count() > 1) {
            return null;
        }

        $soucet = (float) $sPrijmem->sum('monthly_income');

        return $sPrijmem->mapWithKeys(fn (BudgetMember $c) => [
            (int) $c->user_id => round((float) $c->monthly_income / $soucet, 4),
        ])->all();
    }

    public function entries()
    {
        return $this->hasMany(BudgetEntry::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function settlements()
    {
        return $this->hasMany(BudgetSettlement::class)->orderByDesc('settled_through');
    }

    public function gallerySpace()
    {
        return $this->belongsTo(GallerySpace::class);
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

        return max(1, (int) ceil($this->starts_on->diffInDays($konec) / $this->periodDays()));
    }

    /**
     * Kolik dní má jednotka plánu.
     *
     * Plán se zadává „na kategorii za období" a období je buď měsíc, nebo týden. Na
     * čtyřdenní výlet je měsíc špatná jednotka — plán by se dělil číslem, které s délkou
     * cesty nesouvisí, a denní příděl by vyšel jako zlomek toho, co člověk reálně utratí.
     */
    public function periodDays(): float
    {
        return $this->period_unit === 'week' ? 7.0 : 30.44;
    }

    /** Jak se jednotce plánu říká česky — pro popisky, ať je jasné, proti čemu se měří. */
    public function periodLabel(): string
    {
        return $this->period_unit === 'week' ? 'týdně' : 'měsíčně';
    }
}
