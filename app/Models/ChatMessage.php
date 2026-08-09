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
}
