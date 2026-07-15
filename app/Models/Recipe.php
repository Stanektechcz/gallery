<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Recipe extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'updated_by', 'cover_media_id', 'album_id',
        'title', 'summary', 'description', 'category', 'cuisine', 'difficulty', 'status',
        'base_servings', 'prep_minutes', 'cook_minutes', 'rest_minutes', 'estimated_cost', 'currency',
        'calories_per_serving', 'protein_per_serving', 'carbs_per_serving', 'fat_per_serving',
        'dietary_tags', 'occasion_tags', 'equipment', 'source_name', 'source_url', 'tips',
        'storage_notes', 'reheating_notes', 'is_favorite',
    ];

    protected function casts(): array
    {
        return [
            'base_servings' => 'float', 'estimated_cost' => 'float', 'calories_per_serving' => 'float',
            'protein_per_serving' => 'float', 'carbs_per_serving' => 'float', 'fat_per_serving' => 'float',
            'dietary_tags' => 'array', 'occasion_tags' => 'array', 'equipment' => 'array', 'is_favorite' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $recipe) => $recipe->uuid ??= (string) Str::uuid());
    }

    public function space() { return $this->belongsTo(GallerySpace::class, 'gallery_space_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function cover() { return $this->belongsTo(MediaItem::class, 'cover_media_id'); }
    public function album() { return $this->belongsTo(Album::class); }
    public function ingredients() { return $this->hasMany(RecipeIngredient::class)->orderBy('sort_order'); }
    public function steps() { return $this->hasMany(RecipeStep::class)->orderBy('sort_order'); }
    public function cookingSessions() { return $this->hasMany(RecipeCookingSession::class)->orderByDesc('cooked_at')->orderByDesc('planned_for'); }
    public function completedSessions() { return $this->hasMany(RecipeCookingSession::class)->where('status', 'completed'); }
    public function media() { return $this->belongsToMany(MediaItem::class, 'recipe_media')->withPivot(['cooking_session_id', 'recipe_step_id', 'role', 'caption', 'sort_order', 'created_at']); }
}
