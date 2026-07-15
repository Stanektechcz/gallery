<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $fillable = ['recipe_id', 'section', 'name', 'quantity', 'unit', 'quantity_note', 'is_scalable', 'is_optional', 'is_pantry', 'preparation', 'substitutes', 'sort_order'];
    protected function casts(): array { return ['quantity' => 'float', 'is_scalable' => 'boolean', 'is_optional' => 'boolean', 'is_pantry' => 'boolean']; }
    public function recipe() { return $this->belongsTo(Recipe::class); }
}
