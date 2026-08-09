<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ChatGame extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = [
        'uuid', 'conversation_id', 'gallery_space_id', 'created_by',
        'kind', 'status', 'state', 'turn_user_id', 'winner_user_id', 'is_draw',
    ];

    protected function casts(): array
    {
        return ['state' => 'array', 'is_draw' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $game) => $game->uuid ??= (string) Str::uuid());
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
