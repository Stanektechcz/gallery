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
        'uuid', 'gallery_space_id', 'conversation_id', 'reply_to_id', 'game_id', 'created_by', 'body',
        'attachment_type', 'attachment_ref', 'edited_at',
        'media_path', 'media_mime', 'media_size', 'media_remote_url', 'media_width', 'media_height',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            // At rest only: the server holds the key, so this defends a stolen database
            // rather than the server itself. See the migration for what that means.
            'body' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $message) => $message->uuid ??= (string) Str::uuid());
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revisions()
    {
        return $this->hasMany(ChatMessageRevision::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(self::class, 'reply_to_id');
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
