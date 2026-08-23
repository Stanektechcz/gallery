<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jeden zapsaný den.
 *
 * Základní jednotka celého kalendáře: cyklus se z dnů odvozuje, ne naopak.
 */
class CycleDay extends Model
{
    public const FLOWS = ['none', 'spotting', 'light', 'medium', 'heavy'];

    protected $fillable = [
        'uuid', 'user_id', 'gallery_space_id', 'day', 'flow',
        'symptoms', 'moods', 'pain', 'temperature', 'note', 'is_cycle_start',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'symptoms' => 'array',
            'moods' => 'array',
            'temperature' => 'decimal:2',
            'is_cycle_start' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $den) => $den->uuid ??= (string) Str::uuid());
    }

    /** Teče, ať slabě nebo silně — na rozdíl od dne, kdy se zapsala jen nálada. */
    public function isBleeding(): bool
    {
        return $this->flow !== 'none';
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
