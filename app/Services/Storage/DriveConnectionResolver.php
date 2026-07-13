<?php

namespace App\Services\Storage;

use App\Models\MediaItem;
use App\Models\StorageConnection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the usable Drive for a shared gallery space. Media can be uploaded
 * by either partner, while the connected Drive commonly belongs to the space
 * owner; selecting only the uploader's connection left such files local.
 */
class DriveConnectionResolver
{
    public function forMedia(MediaItem $media): ?StorageConnection
    {
        return $this->forSpace($media->gallery_space_id, $media->owner_user_id);
    }

    public function forSpace(int $spaceId, ?int $preferredUserId = null): ?StorageConnection
    {
        $memberIds = DB::table('gallery_space_user')
            ->where('gallery_space_id', $spaceId)
            ->pluck('user_id')
            ->all();

        if ($preferredUserId && !in_array($preferredUserId, $memberIds, true)) {
            $memberIds[] = $preferredUserId;
        }

        if (!$memberIds) return null;

        return StorageConnection::query()
            ->where('provider', 'google_drive')
            ->where('connection_status', 'healthy')
            ->whereNotNull('root_folder_id')
            ->whereIn('owner_user_id', $memberIds)
            ->orderByRaw('CASE WHEN owner_user_id = ? THEN 0 ELSE 1 END', [$preferredUserId ?? 0])
            ->orderByDesc('last_successful_request_at')
            ->first();
    }
}
