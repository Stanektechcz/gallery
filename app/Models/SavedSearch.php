<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedSearch extends Model
{
    protected $fillable = [
        'user_id', 'gallery_space_id', 'name', 'filters_json', 'view_type',
        'layout_config', 'sort_by', 'sort_direction', 'icon', 'color',
        'is_shared', 'is_pinned', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'filters_json' => 'array',
            'layout_config' => 'array',
            'is_shared'    => 'boolean',
            'is_pinned'    => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user()         { return $this->belongsTo(User::class); }
    public function gallerySpace() { return $this->belongsTo(GallerySpace::class); }
}
