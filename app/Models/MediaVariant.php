<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaVariant extends Model
{
    protected $fillable = [
        'media_item_id', 'type', 'disk', 'path',
        'width', 'height', 'size_bytes', 'format',
        'blur_hash', 'dominant_color', 'aspect_ratio',
    ];

    protected function casts(): array
    {
        return ['aspect_ratio' => 'float'];
    }

    public function mediaItem() { return $this->belongsTo(MediaItem::class); }

    public function getUrlAttribute(): string
    {
        if ($this->disk === 'public') {
            return asset('storage/' . $this->path);
        }
        return url('variants/' . $this->path);
    }
}
