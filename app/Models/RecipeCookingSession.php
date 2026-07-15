<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecipeCookingSession extends Model
{
    protected $fillable = [
        'uuid', 'recipe_id', 'created_by', 'calendar_event_id', 'album_id', 'status', 'planned_for',
        'started_at', 'cooked_at', 'finished_at', 'servings', 'actual_duration_minutes', 'overall_rating',
        'taste_rating', 'process_rating', 'appearance_rating', 'actual_cost', 'currency', 'notes',
        'successes', 'failures', 'improvements', 'changes_made', 'partner_feedback', 'would_cook_again',
        'leftovers_notes', 'recipe_snapshot',
    ];
    protected function casts(): array
    {
        return [
            'planned_for' => 'datetime', 'started_at' => 'datetime', 'cooked_at' => 'datetime', 'finished_at' => 'datetime',
            'servings' => 'float', 'overall_rating' => 'float', 'taste_rating' => 'float', 'process_rating' => 'float',
            'appearance_rating' => 'float', 'actual_cost' => 'float', 'would_cook_again' => 'boolean', 'recipe_snapshot' => 'array',
        ];
    }
    protected static function booted(): void { static::creating(fn (self $session) => $session->uuid ??= (string) Str::uuid()); }
    public function recipe() { return $this->belongsTo(Recipe::class); }
    public function author() { return $this->belongsTo(User::class, 'created_by'); }
    public function event() { return $this->belongsTo(CalendarEvent::class, 'calendar_event_id'); }
    public function album() { return $this->belongsTo(Album::class); }
    public function media() { return $this->belongsToMany(MediaItem::class, 'recipe_media', 'cooking_session_id', 'media_item_id')->withPivot(['role', 'caption', 'sort_order', 'created_at']); }
}
