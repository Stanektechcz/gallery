<?php

namespace App\Jobs\Drive;

use App\Models\Album;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateDriveFolderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 60;

    public function __construct(private readonly int $albumId) {}

    public function uniqueId(): string
    {
        return "create-drive-folder-{$this->albumId}";
    }

    public static function dispatch(Album $album): void
    {
        \Illuminate\Support\Facades\Bus::dispatch((new static($album->id))->onQueue('drive'));
    }

    public function handle(): void
    {
        $album = Album::with(['parent', 'gallerySpace.owner'])->find($this->albumId);
        if (!$album) return;
        if ($album->drive_folder_id) return; // Already created

        $owner = $album->gallerySpace->owner;

        $connection = StorageConnection::where('owner_user_id', $owner->id)
            ->where('connection_status', 'healthy')
            ->first();

        if (!$connection) {
            Log::warning("No healthy storage connection for album #{$album->id} Drive folder creation");
            $this->release(300);
            return;
        }

        $provider = new GoogleDriveStorageProvider($connection);

        try {
            $parentFolderId = $album->parent?->drive_folder_id ?? $connection->root_folder_id;

            if (!$parentFolderId) {
                Log::warning("No parent Drive folder for album #{$album->id}");
                $this->release(60);
                return;
            }

            // Check if folder already exists to prevent duplicates
            $existing = $provider->find($parentFolderId, $album->title);
            if ($existing) {
                $album->update([
                    'drive_folder_id'        => $existing['id'],
                    'drive_parent_folder_id' => $parentFolderId,
                    'sync_status'            => 'synced',
                ]);
                return;
            }

            $folder = $provider->createFolder($album->title, $parentFolderId);
            $album->update([
                'drive_folder_id'        => $folder['id'],
                'drive_parent_folder_id' => $parentFolderId,
                'sync_status'            => 'synced',
                'last_drive_sync_at'     => now(),
            ]);

            Log::info("Drive folder created for album #{$album->id}: {$folder['id']}");

        } catch (\Throwable $e) {
            Log::error("Drive folder creation failed for album #{$album->id}", ['error' => $e->getMessage()]);
            $album->update(['sync_status' => 'failed']);
            $delay = min(60 * pow(2, $this->attempts()), 3600);
            $this->release($delay);
        }
    }
}
