<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'body',
        'attachment_type', 'attachment_ref', 'edited_at',
        'media_path', 'media_mime', 'media_size', 'media_remote_url', 'media_width', 'media_height',
    ];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $message) => $message->uuid ??= (string) Str::uuid());
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reactions()
    {
        return $this->hasMany(ChatReaction::class);
    }

    /** A message is worth showing if it says something or carries something. */
    public function hasContent(): bool
    {
        return $this->body !== '' || $this->media_path !== null || $this->media_remote_url !== null;
    }
}
