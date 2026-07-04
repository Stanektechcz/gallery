<?php

namespace App\Policies;

use App\Models\MediaItem;
use App\Models\User;

class MediaPolicy
{
    public function view(User $user, MediaItem $media): bool
    {
        return $user->gallerySpaces()
            ->where('gallery_spaces.id', $media->gallery_space_id)
            ->exists();
    }

    public function update(User $user, MediaItem $media): bool
    {
        if ($user->read_only_mode) return false;
        return $this->view($user, $media);
    }

    public function delete(User $user, MediaItem $media): bool
    {
        if ($user->read_only_mode) return false;
        // Owner can always delete; partners can delete if pivot allows it
        if ($user->isOwner()) return $this->view($user, $media);
        $pivotData = $user->gallerySpaces()
            ->where('gallery_spaces.id', $media->gallery_space_id)
            ->first()?->pivot;
        // Default allow if pivot row exists but can_delete is null (legacy)
        return $pivotData !== null && ($pivotData->can_delete !== false);
    }

    public function restore(User $user, MediaItem $media): bool
    {
        return $this->view($user, $media) && !$user->read_only_mode;
    }
}
