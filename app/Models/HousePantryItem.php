<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Co je doma ve spíži a v lednici.
 *
 * `keywords` jsou slova, pod kterými to hledá kuchařka: recept mluví
 * o „rajčatech", krabice o „loupaných rajčatech".
 */
class HousePantryItem extends Model
{
    protected $table = 'house_pantry';

    protected $fillable = [
        'uuid', 'gallery_space_id', 'name', 'category', 'quantity', 'unit', 'expires_on', 'keywords',
    ];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'keywords' => 'array', 'quantity' => 'float'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $polozka) => $polozka->uuid ??= (string) Str::uuid());
    }
}
