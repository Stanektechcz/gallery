<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class EntertainmentTitle extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'added_by', 'album_id', 'media_type', 'title', 'original_title', 'external_source',
        'external_id', 'release_date', 'release_year', 'runtime_minutes', 'seasons_count', 'overview', 'poster_url',
        'backdrop_url', 'trailer_url', 'original_language', 'genres', 'status', 'priority', 'watch_provider', 'notes',
        'started_at', 'watched_at', 'created_from', 'source_reference',
    ];
    protected function casts(): array { return ['release_date' => 'date', 'genres' => 'array', 'started_at' => 'datetime', 'watched_at' => 'datetime']; }
    protected static function booted(): void {
        static::creating(function (EntertainmentTitle $title): void {
            $title->uuid ??= (string) Str::uuid();
            if (Schema::hasColumn('entertainment_titles', 'created_from')) $title->created_from ??= $title->external_source === 'manual' ? 'manual' : 'import';
        });
    }
    public function votes() { return $this->hasMany(EntertainmentVote::class); }
}
