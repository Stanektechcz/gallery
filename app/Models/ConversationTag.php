<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A label that can sit on any conversation, cutting across categories. */
class ConversationTag extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = ['uuid', 'gallery_space_id', 'name', 'colour'];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_tag', 'conversation_tag_id', 'conversation_id');
    }
}
