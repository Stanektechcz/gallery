<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One day's prompt: the moment both people were asked to photograph at once.
 */
class DailyMoment extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'moment_date', 'notify_at', 'notified_at',
        'window_minutes', 'prompt',
    ];

    protected function casts(): array
    {
        return [
            'moment_date' => 'date',
            'notify_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $moment) => $moment->uuid ??= (string) Str::uuid());
    }

    public function entries()
    {
        return $this->hasMany(DailyMomentEntry::class);
    }

    /** Whether the prompt has actually gone out yet — nothing may be posted before it has. */
    public function isOpen(): bool
    {
        return $this->notify_at->isPast();
    }

    /** The end of being on time. Past it a post still counts, it is just marked late. */
    public function closesAt(): \Illuminate\Support\Carbon
    {
        return $this->notify_at->copy()->addMinutes($this->window_minutes);
    }
}
