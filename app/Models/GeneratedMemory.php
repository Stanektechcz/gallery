<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One card in the memories tab, stored rather than recomputed.
 *
 * Dismissing is a timestamp rather than a delete: the generator would otherwise rebuild
 * tomorrow exactly what somebody dismissed today.
 */
class GeneratedMemory extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'kind', 'title', 'subtitle', 'icon',
        'source_type', 'source_id', 'occurs_on', 'years_ago', 'media_ids',
        'link', 'score', 'notified_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'media_ids' => 'array',
            'occurs_on' => 'date',
            'notified_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());
    }
}
