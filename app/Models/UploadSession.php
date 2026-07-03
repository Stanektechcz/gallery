<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UploadSession extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'gallery_space_id', 'target_album_id',
        'original_filename', 'mime_type', 'total_size', 'total_chunks',
        'received_chunks', 'uploaded_bytes', 'sha256', 'status',
        'assembled_path', 'drive_upload_uri', 'drive_uploaded_bytes',
        'expires_at', 'completed_at', 'resulting_media_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'    => 'datetime',
            'completed_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (UploadSession $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function user()          { return $this->belongsTo(User::class); }
    public function gallerySpace()  { return $this->belongsTo(GallerySpace::class); }
    public function targetAlbum()   { return $this->belongsTo(Album::class, 'target_album_id'); }
    public function resultingMedia(){ return $this->belongsTo(MediaItem::class, 'resulting_media_id'); }

    public function chunks()
    {
        return $this->hasMany(UploadChunk::class)->orderBy('chunk_index');
    }

    public function isComplete(): bool
    {
        return $this->received_chunks >= $this->total_chunks;
    }

    public function completionPercent(): int
    {
        if ($this->total_chunks === 0) return 0;
        return (int) round(($this->received_chunks / $this->total_chunks) * 100);
    }
}
