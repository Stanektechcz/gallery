<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Věc v bytě — spotřebič, nábytek, kolo.
 *
 * Kromě záruky drží i to, co ta věc stojí za rok: pořizovací cena rozpočítaná
 * na životnost, spotřeba a údržba. Bez toho je inventář jen seznam.
 */
class HouseInventoryItem extends Model
{
    protected $table = 'house_inventory';

    protected $fillable = [
        'uuid', 'client_id', 'gallery_space_id', 'name', 'subtitle', 'room',
        'bought_on', 'warranty_to', 'has_doc', 'needs_service', 'service_next_on',
        'service_price', 'price', 'life_years', 'energy_per_year', 'upkeep_per_year',
    ];

    protected function casts(): array
    {
        return [
            'bought_on' => 'date',
            'warranty_to' => 'date',
            'service_next_on' => 'date',
            'has_doc' => 'boolean',
            'needs_service' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $vec) => $vec->uuid ??= (string) Str::uuid());
    }
}
