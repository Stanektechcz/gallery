<?php

namespace App\Jobs\Upload;

use App\Jobs\Media\CalculateMediaHashesJob;
use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\UploadSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AssembleUploadChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(private readonly int $uploadSessionId)
    {
    }

    public static function dispatch(UploadSession $session): void
    {
        static::dispatchJob($session->id)->onQueue('uploads');
    }

    private static function dispatchJob(int $id): \Illuminate\Foundation\Bus\PendingDispatch
    {
        return new static($id);
    }

    public function handle(): void
    {
        $session = UploadSession::with('chunks')->find($this->uploadSessionId);
        if (!$session) return;

        if (!in_array($session->status, ['pending', 'assembling'])) {
            Log::info("Upload session {$session->uuid} already processed, skipping.");
            return;
        }

        try {
            $session->update(['status' => 'assembling']);

            // Sort chunks and assemble
            $chunks   = $session->chunks()->orderBy('chunk_index')->get();
            $destPath = storage_path("app/uploads/{$session->uuid}/{$session->original_filename}");
            @mkdir(dirname($destPath), 0755, true);

            $destHandle = fopen($destPath, 'wb');
            if (!$destHandle) throw new \RuntimeException("Cannot open output file: {$destPath}");

            foreach ($chunks as $chunk) {
                $chunkPath = Storage::disk('local')->path($chunk->path);
                if (!file_exists($chunkPath)) {
                    throw new \RuntimeException("Missing chunk #{$chunk->chunk_index}: {$chunkPath}");
                }

                $chunkHandle = fopen($chunkPath, 'rb');
                stream_copy_to_stream($chunkHandle, $destHandle);
                fclose($chunkHandle);
            }
            fclose($destHandle);

            // Verify file size
            $assembledSize = filesize($destPath);
            if ($assembledSize !== $session->total_size) {
                throw new \RuntimeException("Assembly size mismatch. Expected {$session->total_size}, got {$assembledSize}");
            }

            // SHA-256 verification
            if ($session->sha256) {
                $actualHash = hash_file('sha256', $destPath);
                if ($actualHash !== $session->sha256) {
                    throw new \RuntimeException("SHA-256 mismatch. Expected {$session->sha256}, got {$actualHash}");
                }
            }

            $session->update([
                'status'         => 'received',
                'assembled_path' => $destPath,
            ]);

            // Create the MediaItem record
            $media = MediaItem::create([
                'gallery_space_id'  => $session->gallery_space_id,
                'owner_user_id'     => $session->user_id,
                'uploaded_by'       => $session->user_id,
                'primary_album_id'  => $session->target_album_id,
                'original_filename' => $session->original_filename,
                'safe_filename'     => preg_replace('/[^a-zA-Z0-9._-]/', '_', $session->original_filename),
                'extension'         => strtolower(pathinfo($session->original_filename, PATHINFO_EXTENSION)),
                'mime_type'         => $session->mime_type,
                'media_type'        => str_starts_with($session->mime_type, 'video/') ? 'video' : 'photo',
                'size_bytes'        => $assembledSize,
                'sha256'            => $session->sha256,
                'status'            => 'received',
                'uploaded_at'       => now(),
            ]);

            $session->update([
                'status'             => 'completed',
                'completed_at'       => now(),
                'resulting_media_id' => $media->id,
            ]);

            // Dispatch processing pipeline
            CalculateMediaHashesJob::dispatch($media)->onQueue('media');

            // Cleanup chunks
            Storage::disk('local')->deleteDirectory("upload_chunks/{$session->uuid}");

            AuditLog::record('media.upload', $media, ['filename' => $media->original_filename]);
            Log::info("Upload assembled for session {$session->uuid}, media #{$media->id}");

        } catch (\Throwable $e) {
            $session->update(['status' => 'failed']);
            Log::error("Upload assembly failed for session {$session->uuid}", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $session = UploadSession::find($this->uploadSessionId);
        $session?->update(['status' => 'failed']);
        Log::error("AssembleUploadChunksJob failed", ['session_id' => $this->uploadSessionId, 'error' => $e->getMessage()]);
    }
}
