<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MemoryEvening extends Model
{
    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'calendar_event_id', 'curation_board_id', 'album_id',
        'shared_memory_moment_id', 'fingerprint', 'dedupe_key', 'source_type', 'title', 'description',
        'scheduled_for', 'status', 'repeat_annually', 'started_at', 'completed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'repeat_annually' => 'boolean', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $evening) => $evening->uuid ??= (string) Str::uuid());
    }

    public function event() { return $this->belongsTo(CalendarEvent::class, 'calendar_event_id'); }
    public function album() { return $this->belongsTo(Album::class); }
}
