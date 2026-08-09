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
    public const KIND_CHANNEL = 'channel';

    public const VISIBILITY_OPEN = 'open';
    public const VISIBILITY_INVITE = 'invite';

    protected $fillable = [
        'uuid', 'gallery_space_id', 'conversation_category_id', 'created_by', 'kind', 'visibility',
        'title', 'topic', 'icon', 'position', 'is_default', 'is_archived', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'is_default' => 'boolean',
            'is_archived' => 'boolean',
        ];
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

    public function category()
    {
        return $this->belongsTo(ConversationCategory::class, 'conversation_category_id');
    }

    public function tags()
    {
        return $this->belongsToMany(ConversationTag::class, 'conversation_tag', 'conversation_id', 'conversation_tag_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isGroup(): bool
    {
        return $this->kind === self::KIND_GROUP;
    }

    public function isChannel(): bool
    {
        return $this->kind === self::KIND_CHANNEL;
    }

    /** An open channel belongs to the space, so membership is not a list. */
    public function isOpen(): bool
    {
        return $this->visibility === self::VISIBILITY_OPEN;
    }

    /** Only conversations the user is actually in. There is no other way to reach one. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        /*
         | Two ways to be in a conversation, and channels are why:
         |
         |   a participant row  — direct chats, groups, private channels
         |   membership of the space — open channels, which need no invitation
         |
         | Expressed as one condition so every existing caller keeps working without
         | knowing channels exist.
         */
        return $query->where(fn (Builder $outer) => $outer
            ->whereHas('participants', fn (Builder $inner) => $inner->where('user_id', $user->id))
            ->orWhere(fn (Builder $open) => $open
                ->where('kind', self::KIND_CHANNEL)
                ->where('visibility', self::VISIBILITY_OPEN)
                // The spaces this person belongs to, read straight from the pivot: there
                // is no space relation on this model to hang a whereHas on.
                ->whereIn('gallery_space_id', GallerySpace::whereHas(
                    'members',
                    fn ($member) => $member->whereKey($user->id),
                )->select('id'))));
    }

    /**
     * What to call this conversation when showing it to a particular person.
     *
     * A group has a name. A direct chat does not — it is "the other person", and which
     * person that is depends on who is looking.
     */
    public function titleFor(User $viewer): string
    {
        if ($this->isChannel()) return $this->title ?: 'kanál';
        if ($this->isGroup()) return $this->title ?: 'Skupina';

        $other = $this->members->firstWhere('id', '!=', $viewer->id);

        return $other?->name ?? 'Konverzace';
    }
}
