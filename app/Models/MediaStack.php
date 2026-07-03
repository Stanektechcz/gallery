<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MediaStack extends Model
{
    protected $fillable = ['uuid', 'gallery_space_id', 'name', 'cover_media_id', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (MediaStack $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function gallerySpace() { return $this->belongsTo(GallerySpace::class); }
    public function cover()        { return $this->belongsTo(MediaItem::class, 'cover_media_id'); }

    public function items()
    {
        return $this->belongsToMany(MediaItem::class, 'media_stack_items')
            ->withPivot(['sort_order', 'is_cover'])
            ->orderByPivot('sort_order');
    }
}
