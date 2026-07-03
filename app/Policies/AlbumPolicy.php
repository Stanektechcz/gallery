<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\User;

class AlbumPolicy
{
    public function view(User $user, Album $album): bool
    {
        return $this->userInSpace($user, $album->gallery_space_id);
    }

    public function update(User $user, Album $album): bool
    {
        if ($user->read_only_mode) return false;
        if ($user->isAdmin()) return $this->userInSpace($user, $album->gallery_space_id);

        // Check explicit permission
        $perm = $album->userPermissions()->where('user_id', $user->id)->first();
        return $perm && in_array($perm->role, ['editor']) && $this->userInSpace($user, $album->gallery_space_id);
    }

    public function delete(User $user, Album $album): bool
    {
        if ($user->read_only_mode) return false;
        $pivotData = $user->gallerySpaces()
            ->where('gallery_spaces.id', $album->gallery_space_id)
            ->first()?->pivot;
        return $pivotData && $pivotData->can_delete;
    }

    private function userInSpace(User $user, int $spaceId): bool
    {
        return $user->gallerySpaces()->where('gallery_spaces.id', $spaceId)->exists();
    }
}
