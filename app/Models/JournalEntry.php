<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A diary entry. Private to its author until they decide otherwise, one entry at a time.
 *
 * The space scope keeps other spaces out; the readableBy() scope below keeps the other
 * member of *this* space out of entries that were never shared. Both are needed — the
 * first is about tenancy, the second about privacy inside a couple.
 */
class JournalEntry extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;
    use SoftDeletes;

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_SHARED = 'shared';

    protected $fillable = [
        'uuid', 'gallery_space_id', 'created_by', 'title', 'body',
        'mood', 'entry_date', 'visibility', 'shared_at',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'shared_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->uuid ??= (string) Str::uuid());
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isShared(): bool
    {
        return $this->visibility === self::VISIBILITY_SHARED;
    }

    /** Mine, plus whatever the others in this space chose to share. */
    public function scopeReadableBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('created_by', $user->id)
            ->orWhere('visibility', self::VISIBILITY_SHARED));
    }

    /** Only the author may edit, share or delete — sharing does not transfer ownership. */
    public function isEditableBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }
}
