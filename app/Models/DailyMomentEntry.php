<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One person's answer to a day's prompt.
 *
 * Not scoped to a space of its own: it reaches the space through its moment, and adding
 * a second scope here would mean two places to keep in step for no gain.
 */
class DailyMomentEntry extends Model
{
    protected $fillable = [
        'uuid', 'daily_moment_id', 'user_id',
        'back_media_id', 'front_media_id', 'caption', 'posted_at', 'late_minutes',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->uuid ??= (string) Str::uuid());
    }

    public function moment()
    {
        return $this->belongsTo(DailyMoment::class, 'daily_moment_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function back()
    {
        return $this->belongsTo(MediaItem::class, 'back_media_id');
    }

    public function front()
    {
        return $this->belongsTo(MediaItem::class, 'front_media_id');
    }
}
