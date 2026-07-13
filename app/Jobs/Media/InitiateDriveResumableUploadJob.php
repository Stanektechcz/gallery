<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Services\Storage\DriveConnectionResolver;
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

        // A completed Drive upload is immutable. Re-dispatches from preview
        // repair and metadata jobs must not create duplicate remote files.
        if ($media->drive_file_id) {
            if ($media->storage_status !== 'synced') $media->update(['storage_status' => 'synced']);
            return;
        }

        $session = UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        // The assembled upload is temporary. The durable local original is
        // deliberately also a valid source for a delayed Drive upload.
        if (!$path || !is_file($path)) {
            $original = $media->variants()->where('type', 'original')->first();
            $candidate = $original ? \Illuminate\Support\Facades\Storage::disk($original->disk)->path($original->path) : null;
            $path = $candidate && is_file($candidate) ? $candidate : null;
        }

        if (!$path || !is_file($path)) {
            $media->update(['processing_error' => 'Zdroj pro synchronizaci do úložiště nebyl nalezen.']);
            return;
        }

        $connection = app(DriveConnectionResolver::class)->forMedia($media);

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
