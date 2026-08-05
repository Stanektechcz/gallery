<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The customer's own choice of which entitled features they want visible. */
class SpaceFeature extends Model
{
    protected $fillable = ['gallery_space_id', 'feature_id', 'enabled'];
    protected function casts(): array { return ['enabled' => 'boolean']; }

    public function feature() { return $this->belongsTo(Feature::class); }
}
