<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InitiateDriveResumableUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 120;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $session = UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        if (!$path || !file_exists($path)) {
            $media->update(['processing_error' => 'Zdroj pro synchronizaci do úložiště nebyl nalezen.']);
            return;
        }

        // Get active storage connection for owner
        $connection = StorageConnection::where('owner_user_id', $media->owner_user_id)
            ->where('provider', 'google_drive')
            ->where('connection_status', 'healthy')
            ->first();

        if (!$connection) {
            // Lokální originál je plnohodnotné úložiště. Bez připojeného Drivu
            // nechceme nekonečně hromadit frontu ani znepřístupnit médium.
            $media->update(['storage_status' => 'local_only']);
            Log::info("No Drive connection for media #{$media->id}; keeping local copy as authoritative.");
            return;
        }

        $provider = new GoogleDriveStorageProvider($connection);

        try {
            // Determine Drive parent folder
            $driveFolderId = $media->primaryAlbum?->drive_folder_id
                ?? $connection->root_folder_id;

            if (!$driveFolderId) {
                Log::warning("Drive folder not configured for media #{$media->id}");
                $this->release(60);
                return;
            }

            $totalSize  = filesize($path);
            $remoteName = $media->safe_filename ?: $media->original_filename;
            $sessionUri = $provider->createResumableSession($remoteName, $driveFolderId, $media->mime_type, $totalSize);

            // Save the resumable session URI in upload_sessions
            $session?->update(['drive_upload_uri' => $sessionUri]);

            $media->update([
                'storage_status'   => 'uploading',
                'processing_stage' => 'uploading_to_drive',
            ]);

            // Dispatch chunk upload job
            UploadDriveChunkJob::dispatch($media, $session, $sessionUri, 0, $totalSize)->onQueue('drive');

        } catch (\Throwable $e) {
            Log::error("Drive upload initiation failed for media #{$media->id}", ['error' => $e->getMessage()]);
            $this->retryWithBackoff($e);
        }
    }

    private function retryWithBackoff(\Throwable $e): void
    {
        $delay = min(60 * pow(2, $this->attempts()), 3600); // exponential backoff, max 1 hour
        $this->release($delay);
    }
}
