<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use App\Services\Planning\LifeEventService;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'trip_id', 'source_trip_id', 'album_id', 'title',
        'description', 'type', 'status', 'starts_at', 'ends_at', 'all_day', 'timezone',
        'place_name', 'latitude', 'longitude', 'departure_buffer_minutes', 'recurrence_rule',
        'color', 'is_private', 'metadata', 'created_from', 'source_reference', 'last_reminder_at',
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
        static::creating(function (self $event): void {
            $event->uuid ??= (string) Str::uuid();
            $source = is_array($event->metadata) ? ($event->metadata['source'] ?? null) : null;
            if (Schema::hasColumn('calendar_events', 'created_from')) $event->created_from ??= $source ?? 'manual';
        });
        static::created(function (self $event): void {
            $source = is_array($event->metadata) ? ($event->metadata['source'] ?? 'calendar') : 'calendar';
            app(LifeEventService::class)->record($event->gallery_space_id, $event->created_by, 'calendar.event.created', $event->title, $source, self::class, $event->id, $event->starts_at, ['type' => $event->type, 'status' => $event->status]);
        });
    }

    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function participants() { return $this->belongsToMany(User::class, 'event_participants', 'event_id', 'user_id')->withPivot(['role', 'response'])->withTimestamps(); }
    public function tasks() { return $this->hasMany(EventTask::class, 'event_id')->orderBy('sort_order'); }
    public function attachments() { return $this->hasMany(EventAttachment::class, 'event_id'); }
    public function reminders() { return $this->hasMany(EventReminder::class, 'event_id'); }
    public function recipeCookingSessions() { return $this->hasMany(RecipeCookingSession::class, 'calendar_event_id'); }
}
