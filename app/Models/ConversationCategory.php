<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A heading in the channel list. Display only — see the migration for why it carries no
 * permissions of its own.
 */
class ConversationCategory extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = ['uuid', 'gallery_space_id', 'created_by', 'name', 'icon', 'position'];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}
