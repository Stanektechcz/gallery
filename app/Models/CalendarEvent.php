<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'trip_id', 'album_id', 'title',
        'description', 'type', 'status', 'starts_at', 'ends_at', 'all_day', 'timezone',
        'place_name', 'latitude', 'longitude', 'departure_buffer_minutes', 'recurrence_rule',
        'color', 'is_private', 'metadata', 'last_reminder_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean',
            'is_private' => 'boolean', 'recurrence_rule' => 'array', 'metadata' => 'array',
            'last_reminder_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $event) => $event->uuid ??= (string) Str::uuid());
    }

    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function participants() { return $this->belongsToMany(User::class, 'event_participants', 'event_id', 'user_id')->withPivot(['role', 'response'])->withTimestamps(); }
    public function tasks() { return $this->hasMany(EventTask::class, 'event_id')->orderBy('sort_order'); }
    public function attachments() { return $this->hasMany(EventAttachment::class, 'event_id'); }
    public function reminders() { return $this->hasMany(EventReminder::class, 'event_id'); }
}
