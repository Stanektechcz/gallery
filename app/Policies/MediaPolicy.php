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
        $pivotData = $user->gallerySpaces()
            ->where('gallery_spaces.id', $media->gallery_space_id)
            ->first()?->pivot;
        return $pivotData && $pivotData->can_delete;
    }

    public function restore(User $user, MediaItem $media): bool
    {
        return $this->view($user, $media) && !$user->read_only_mode;
    }
}
