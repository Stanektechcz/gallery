<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves which gallery spaces the current request may see, and caches the answer for
 * the lifetime of the request so the global scope does not re-query on every statement.
 */
class SpaceContext
{
    public const SCOPE = 'gallery_space';

    private static ?array $cache = null;
    private static ?int $cachedForUserId = null;

    /**
     * Space ids visible to the signed-in user, or null when there is no user at all
     * (console, queue, seeders) and the scope should stand aside.
     *
     * @return list<int>|null
     */
    public static function currentSpaceIds(): ?array
    {
        $user = Auth::user();
        if (! $user instanceof User) return null;

        if (self::$cache !== null && self::$cachedForUserId === $user->id) {
            return self::$cache;
        }

        self::$cachedForUserId = $user->id;
        self::$cache = $user->gallerySpaces()->pluck('gallery_spaces.id')->map(fn ($id) => (int) $id)->all();

        return self::$cache;
    }

    /** Membership changes mid-request (accepting an invitation, creating a space) must invalidate this. */
    public static function forget(): void
    {
        self::$cache = null;
        self::$cachedForUserId = null;
    }
}
