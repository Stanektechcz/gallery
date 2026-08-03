<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Shared, queryable history that links a real-world moment to its source entity.
 */
class LifeEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'kind', 'title', 'source',
        'subject_type', 'subject_id', 'occurred_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $event) => $event->uuid ??= (string) Str::uuid());
    }
}