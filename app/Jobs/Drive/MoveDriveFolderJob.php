<?php

namespace App\Jobs\Drive;

use App\Models\Album;
use App\Services\Storage\DriveConnectionResolver;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class MoveDriveFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        private readonly int $albumId,
        private readonly ?string $newParentDriveFolderId,
    ) {}

    public static function dispatch(Album $album, ?string $newParentDriveFolderId): void
    {
        // Úloha se musí do fronty opravdu odeslat — samotné `new static(...)->onQueue()`
        // ji jen sestavilo a zahodilo, takže změna na Google Disku nikdy neproběhla.
        Bus::dispatch((new static($album->id, $newParentDriveFolderId))->onQueue('drive'));
    }

    public function handle(): void
    {
        $album = Album::with('gallerySpace.owner')->find($this->albumId);
        if (! $album || ! $album->drive_folder_id || ! $this->newParentDriveFolderId) {
            return;
        }

        // Jen Google Disk dvojice — viz CreateDriveFolderJob.
        $connection = app(DriveConnectionResolver::class)
            ->forSpace((int) $album->gallery_space_id, $album->gallerySpace?->owner_id);

        if (! $connection) {
            $this->release(300);

            return;
        }

        try {
            $provider = app(GoogleDriveStorageProvider::class, ['connection' => $connection]);
            $provider->moveFolder($album->drive_folder_id, $this->newParentDriveFolderId);
            $album->update([
                'drive_parent_folder_id' => $this->newParentDriveFolderId,
                'sync_status' => 'synced',
                'last_drive_sync_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Drive folder move failed for album #{$album->id}", ['error' => $e->getMessage()]);
            $album->update(['sync_status' => 'failed']);
            $this->release(min(60 * pow(2, $this->attempts()), 3600));
        }
    }
}
