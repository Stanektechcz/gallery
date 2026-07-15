<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecipeStep extends Model
{
    protected $fillable = ['uuid', 'recipe_id', 'media_item_id', 'title', 'instruction', 'timer_seconds', 'temperature', 'temperature_unit', 'equipment', 'tip', 'sort_order'];
    protected function casts(): array { return ['temperature' => 'float']; }
    protected static function booted(): void { static::creating(fn (self $step) => $step->uuid ??= (string) Str::uuid()); }
    public function recipe() { return $this->belongsTo(Recipe::class); }
    public function media() { return $this->belongsTo(MediaItem::class, 'media_item_id'); }
}
