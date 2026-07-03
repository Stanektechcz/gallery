<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaVariant extends Model
{
    protected $fillable = [
        'media_item_id',
        'type',
        'disk',
        'path',
        'width',
        'height',
        'size_bytes',
        'format',
        'mime_type',
        'blur_hash',
        'dominant_color',
        'aspect_ratio',
    ];

    protected function casts(): array
    {
        return ['aspect_ratio' => 'float'];
    }

    public function mediaItem()
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function getUrlAttribute(): string
    {
        if ($this->disk === 'public') {
            // Use Laravel proxy route to avoid Apache symlink permission issues
            return url('/files/' . ltrim($this->path, '/'));
        }
        return url('variants/' . $this->path);
    }
}
