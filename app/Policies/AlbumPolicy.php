<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;

class AlbumPolicy
{
    /**
     * `isAdmin()` dřív rozhodovalo tady — a `users.role` je `owner` u každého
     * založeného účtu, ne u role v tomhle konkrétním prostoru. Vlastník svého
     * vlastního prostoru, který je jinde jen host (`viewer`), tak směl otevřít,
     * přejmenovat i přemístit album galerie, do které byl pozvaný jen na
     * prohlížení. Rozhoduje výhradně role v prostoru **tohohle** alba.
     */
    public function view(User $user, Album $album): bool
    {
        $role = $this->roleInSpace($user, $album->gallery_space_id);
        if ($role === null) {
            return false;
        }

        if (in_array($role, PristupDoGalerie::ROLE_DVOJICE, true)) {
            return true;
        }

        // Host prostoru vidí „jen sdílené odkazy" (viz `PristupDoGalerie`) — na tohle
        // album ale smí, když mu ho někdo výslovně přidal do `album_user_permissions`.
        return $album->userPermissions()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Album $album): bool
    {
        if ($user->read_only_mode) {
            return false;
        }

        $role = $this->roleInSpace($user, $album->gallery_space_id);
        if ($role === null) {
            return false;
        }

        if (in_array($role, PristupDoGalerie::ROLE_DVOJICE, true)) {
            return true;
        }

        // Explicitní právo na tohle konkrétní album, i pro hosta prostoru.
        $perm = $album->userPermissions()->where('user_id', $user->id)->first();

        return $perm && in_array($perm->role, ['editor']);
    }

    public function delete(User $user, Album $album): bool
    {
        if ($user->read_only_mode) {
            return false;
        }
        $pivotData = $user->gallerySpaces()
            ->where('gallery_spaces.id', $album->gallery_space_id)
            ->first()?->pivot;

        return $pivotData && $pivotData->can_delete;
    }

    /** Role uživatele v konkrétním prostoru, nebo `null`, když v něm není. */
    private function roleInSpace(User $user, int $spaceId): ?string
    {
        $clenstvi = $user->gallerySpaces()->where('gallery_spaces.id', $spaceId)->first();
        if (! $clenstvi) {
            return null;
        }

        if ((int) $clenstvi->owner_id === (int) $user->id) {
            return 'owner';
        }

        return (string) $clenstvi->pivot->role;
    }
}
