<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * One person's connection to an outside service, personal or shared with their space.
 *
 * Credentials never leave this class as plain text by accident: they are hidden from
 * serialisation, and the only way out is credentials(), which every caller has to reach
 * for deliberately.
 */
class UserIntegration extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    public const VISIBILITY_PERSONAL = 'personal';
    public const VISIBILITY_SHARED = 'shared';

    protected $fillable = [
        'uuid', 'gallery_space_id', 'user_id', 'provider', 'visibility', 'label',
        'encrypted_credentials', 'account_id', 'account_name', 'account_avatar',
        'status', 'last_error', 'last_used_at', 'expires_at',
    ];

    /** Belt and braces: even a careless toArray() cannot leak the secret. */
    protected $hidden = ['encrypted_credentials'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->uuid ??= (string) Str::uuid());
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function documents()
    {
        return $this->hasMany(IntegrationDocument::class);
    }

    /** @return array<string, mixed> */
    public function credentials(): array
    {
        if (! $this->encrypted_credentials) return [];

        try {
            return json_decode(Crypt::decryptString($this->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // A key rotation or a corrupt row must not take a page down with it.
            return [];
        }
    }

    /** @param  array<string, mixed>  $credentials */
    public function setCredentials(array $credentials): void
    {
        $this->encrypted_credentials = Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    public function isShared(): bool
    {
        return $this->visibility === self::VISIBILITY_SHARED;
    }

    /** A token that has run out is as good as no connection at all. */
    public function isUsable(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Connections this user may read through: their own, plus what the space shares.
     *
     * Mirrors the diary deliberately — the rule for "mine or shared with me" should look
     * the same everywhere in this app, so nobody has to re-derive it.
     */
    public function scopeReadableBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('user_id', $user->id)
            ->orWhere('visibility', self::VISIBILITY_SHARED));
    }

    /** Only the person who connected it may edit or disconnect it. */
    public function isManageableBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now(), 'status' => 'active', 'last_error' => null])->save();
    }

    public function markFailed(string $message): void
    {
        // Trimmed: provider errors can carry a whole HTML page.
        $this->forceFill(['status' => 'error', 'last_error' => Str::limit($message, 500)])->save();
    }
}
