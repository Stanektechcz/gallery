<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A page pulled in from an outside service.
 *
 * Only enough is kept to list, search and link out: title, icon, a plain-text excerpt
 * and the address. The body stays where it lives — mirroring someone's whole Notion into
 * our database would make us responsible for content we do not own and cannot keep
 * current.
 */
class IntegrationDocument extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = [
        'uuid', 'user_integration_id', 'gallery_space_id', 'provider', 'external_id',
        'kind', 'title', 'url', 'icon', 'excerpt', 'external_updated_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['external_updated_at' => 'datetime', 'synced_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());
    }

    public function integration()
    {
        return $this->belongsTo(UserIntegration::class, 'user_integration_id');
    }
}
