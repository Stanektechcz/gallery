<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jedna opakovaná práce v domácnosti.
 *
 * `last_done_at` je skutečný okamžik, ne popisek: „před 11 dny" se dá z data
 * spočítat, z popisku datum ne — a na tom stojí, jestli je práce po termínu.
 */
class HouseChore extends Model
{
    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'name', 'every', 'assigned_to',
        'rotate', 'minutes', 'day', 'icon', 'last_done_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['rotate' => 'boolean', 'last_done_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $prace) => $prace->uuid ??= (string) Str::uuid());
    }

    public function odpovedny()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Za kolik dní se práce má opakovat.
     *
     * Bez toho se nedá poznat, co je po termínu — a právě to je jediné, co
     * z celého seznamu potřebuje pozornost.
     */
    public function dniOpakovani(): ?int
    {
        return match (true) {
            str_contains($this->every, 'denně') => 1,
            str_contains($this->every, '2×') => 3,
            str_contains($this->every, 'týdně') => 7,
            str_contains($this->every, 'měsíčně') => 30,
            default => null,
        };
    }
}
