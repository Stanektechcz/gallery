<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CoupleDateIdea extends Model
{
    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'calendar_event_id', 'trip_id',
        'generation_key', 'title', 'summary', 'theme', 'status', 'travel_scope',
        'transport_mode', 'estimated_cost', 'currency', 'estimated_minutes',
        'novelty_percent', 'suggested_starts_at', 'destination', 'parameters', 'plan',
    ];

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'float',
            'estimated_minutes' => 'integer',
            'novelty_percent' => 'integer',
            'suggested_starts_at' => 'datetime',
            'destination' => 'array',
            'parameters' => 'array',
            'plan' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $idea) => $idea->uuid ??= (string) Str::uuid());
    }

    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function event() { return $this->belongsTo(CalendarEvent::class, 'calendar_event_id'); }
    public function reactions() { return $this->hasMany(CoupleDateIdeaReaction::class, 'date_idea_id'); }
}
