<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\UploadSession;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadDriveChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 10;
    public int $timeout = 600;

    private const CHUNK_SIZE = 8 * 1024 * 1024; // 8 MB

    public function __construct(
        private readonly int    $mediaItemId,
        private readonly ?int   $uploadSessionId,
        private readonly string $driveSessionUri,
        private readonly int    $startByte,
        private readonly int    $totalSize,
    ) {}

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $session = $this->uploadSessionId ? UploadSession::find($this->uploadSessionId) : null;
        $path    = $session?->assembled_path;

        if (!$path || !file_exists($path)) {
            $media->update(['status' => 'failed', 'processing_error' => 'Source file missing for Drive chunk upload']);
            return;
        }

        $connection = StorageConnection::where('owner_user_id', $media->owner_user_id)
            ->where('provider', 'google_drive')
            ->whereIn('connection_status', ['healthy', 'refresh_required'])
            ->first();

        if (!$connection) {
            Log::warning("No storage connection for Drive chunk upload, media #{$media->id}");
            $this->release(300);
            return;
        }

        $provider = new GoogleDriveStorageProvider($connection);

        try {
            // First query current resumable status to get actual uploaded bytes
            $status = $provider->queryResumableStatus($this->driveSessionUri, $this->totalSize);

            if ($status['status'] === 'complete') {
                $this->finalizeMedia($media, $session, $status['file'] ?? []);
                return;
            }

            $startByte = $status['uploaded_bytes'] ?? $this->startByte;

            // Read and upload next chunk
            $handle = fopen($path, 'rb');
            fseek($handle, $startByte);
            $chunk    = fread($handle, self::CHUNK_SIZE);
            fclose($handle);

            if ($chunk === false || strlen($chunk) === 0) {
                Log::warning("No data to upload at position {$startByte} for media #{$media->id}");
                return;
            }

            $chunkLen = strlen($chunk);
            $endByte  = $startByte + $chunkLen - 1;

            $result = $provider->uploadChunk($this->driveSessionUri, $chunk, $startByte, $endByte, $this->totalSize);

            if ($result['status'] === 'complete') {
                $this->finalizeMedia($media, $session, $result['file'] ?? []);
            } else {
                // Dispatch next chunk
                $session?->update(['drive_uploaded_bytes' => $endByte + 1]);
                static::dispatch(
                    $media, $session, $this->driveSessionUri, $endByte + 1, $this->totalSize
                )->onQueue('drive');
            }

        } catch (\Throwable $e) {
            Log::error("Drive chunk upload failed for media #{$media->id}", [
                'error'      => $e->getMessage(),
                'start_byte' => $this->startByte,
            ]);

            // Exponential backoff
            $delay = min(30 * pow(2, $this->attempts()), 3600);
            $this->release($delay);
        }
    }

    private function finalizeMedia(MediaItem $media, ?UploadSession $session, array $driveFile): void
    {
        $media->update([
            'drive_file_id'     => $driveFile['id'] ?? null,
            'storage_status'    => 'stored',
            'status'            => 'ready',
            'processing_stage'  => null,
            'processing_progress' => 100,
        ]);

        // Clean up temporary assembled file
        if ($session?->assembled_path && file_exists($session->assembled_path)) {
            @unlink($session->assembled_path);
            // Try to remove empty dir
            @rmdir(dirname($session->assembled_path));
        }

        // Build search text now that everything is ready
        $media->load(['tags', 'people', 'places', 'primaryAlbum']);
        $media->rebuildSearchText();

        Log::info("Media #{$media->id} uploaded to Drive: {$driveFile['id']}");
    }

    public static function dispatch(MediaItem $media, ?UploadSession $session, string $uri, int $start, int $total): \Illuminate\Foundation\Bus\PendingDispatch
    {
        return new static($media->id, $session?->id, $uri, $start, $total);
    }
}
