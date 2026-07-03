<?php

namespace App\Jobs\Drive;

use App\Models\Album;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RenameDriveFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 60;

    public function __construct(
        private readonly int    $albumId,
        private readonly string $newName,
    ) {}

    public static function dispatch(Album $album, string $newName): void
    {
        (new static($album->id, $newName))->onQueue('drive');
    }

    public function handle(): void
    {
        $album = Album::with('gallerySpace.owner')->find($this->albumId);
        if (!$album || !$album->drive_folder_id) return;

        $connection = StorageConnection::where('owner_user_id', $album->gallerySpace->owner->id)
            ->where('connection_status', 'healthy')
            ->first();

        if (!$connection) {
            $this->release(300);
            return;
        }

        try {
            $provider = new GoogleDriveStorageProvider($connection);
            $provider->renameFolder($album->drive_folder_id, $this->newName);
            $album->update(['sync_status' => 'synced', 'last_drive_sync_at' => now()]);
        } catch (\Throwable $e) {
            Log::error("Drive folder rename failed for album #{$album->id}", ['error' => $e->getMessage()]);
            $album->update(['sync_status' => 'failed']);
            $this->release(min(60 * pow(2, $this->attempts()), 3600));
        }
    }
}
