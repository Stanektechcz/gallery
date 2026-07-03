<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DuplicateGroup extends Model
{
    protected $fillable = [
        'uuid', 'gallery_space_id', 'match_type', 'resolution',
        'detected_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function gallerySpace() { return $this->belongsTo(GallerySpace::class); }

    public function items()
    {
        return $this->hasMany(DuplicateGroupItem::class);
    }

    public function mediaItems()
    {
        return $this->belongsToMany(MediaItem::class, 'duplicate_group_items')
            ->withPivot(['is_kept'])
            ->withTimestamps();
    }
}
