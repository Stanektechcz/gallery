<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A conversation and the people in it.
 *
 * Membership is a row, not an inference. That is the whole point of this table: the old
 * design asked "who is in this space" and hoped that was the answer, which is why a chat
 * could show nobody, and why there was no way to say "these three, not those five".
 */
class Conversation extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;
    use SoftDeletes;

    public const KIND_DIRECT = 'direct';
    public const KIND_GROUP = 'group';

    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'kind', 'title', 'icon', 'last_message_at',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());
    }

    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'last_read_message_id', 'muted_until'])
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isGroup(): bool
    {
        return $this->kind === self::KIND_GROUP;
    }

    /** Only conversations the user is actually in. There is no other way to reach one. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', fn (Builder $inner) => $inner->where('user_id', $user->id));
    }

    /**
     * What to call this conversation when showing it to a particular person.
     *
     * A group has a name. A direct chat does not — it is "the other person", and which
     * person that is depends on who is looking.
     */
    public function titleFor(User $viewer): string
    {
        if ($this->isGroup()) return $this->title ?: 'Skupina';

        $other = $this->members->firstWhere('id', '!=', $viewer->id);

        return $other?->name ?? 'Konverzace';
    }
}
