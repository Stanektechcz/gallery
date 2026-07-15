<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PlannedMeal extends Model
{
    protected $fillable = [
        'uuid', 'gallery_space_id', 'recipe_id', 'calendar_event_id', 'trip_id', 'trip_day_id', 'trip_activity_id',
        'cooking_session_id', 'created_by', 'meal_type', 'planned_for', 'servings', 'status',
        'notes', 'estimated_cost', 'currency',
    ];

    protected function casts(): array
    {
        return ['planned_for' => 'datetime', 'servings' => 'float', 'estimated_cost' => 'float'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $meal) => $meal->uuid ??= (string) Str::uuid());
    }

    public function recipe() { return $this->belongsTo(Recipe::class); }
    public function event() { return $this->belongsTo(CalendarEvent::class, 'calendar_event_id'); }
    public function cookingSession() { return $this->belongsTo(RecipeCookingSession::class, 'cooking_session_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
