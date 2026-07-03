<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedSearch extends Model
{
    protected $fillable = ['user_id', 'gallery_space_id', 'name', 'filters_json', 'is_shared'];

    protected function casts(): array
    {
        return [
            'filters_json' => 'array',
            'is_shared'    => 'boolean',
        ];
    }

    public function user()         { return $this->belongsTo(User::class); }
    public function gallerySpace() { return $this->belongsTo(GallerySpace::class); }
}
