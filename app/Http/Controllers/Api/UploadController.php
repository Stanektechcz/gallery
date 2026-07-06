<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Media\CalculateMediaHashesJob;
use App\Models\AuditLog;
use App\Models\Album;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\UploadChunk;
use App\Models\UploadSession;
use App\Services\ExifExtractorService;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    private const CHUNK_DISK = 'local';
    private const CHUNK_DIR  = 'upload_chunks';

    /**
     * POST /api/v1/uploads/check-duplicate
     * Check whether a file with given SHA-256 already exists in the gallery space.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $v = $request->validate(['sha256' => 'required|string|size:64']);

        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $existing = MediaItem::where('gallery_space_id', $space->id)
            ->where('sha256', $v['sha256'])
            ->whereNull('trashed_at')
            ->first(['uuid', 'original_filename', 'taken_at']);

        if ($existing) {
            return response()->json([
                'exists'     => true,
                'media_uuid' => $existing->uuid,
                'filename'   => $existing->original_filename,
            ]);
        }

        return response()->json(['exists' => false]);
    }

    /**
     * Initiate a new resumable upload session.
     * POST /api/v1/uploads
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename'        => 'required|string|max:512',
            'mime_type'       => 'required|string|max:100',
            'total_size'      => 'required|integer|min:1',
            'total_chunks'    => 'required|integer|min:1',
            'sha256'          => 'nullable|string|size:64',
            'target_album_id' => 'nullable|integer|exists:albums,id',
        ]);

        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $session = UploadSession::create([
            'user_id'          => $user->id,
            'gallery_space_id' => $space->id,
            'target_album_id'  => $validated['target_album_id'] ?? null,
            'original_filename' => $validated['filename'],
            'mime_type'        => $validated['mime_type'],
            'total_size'       => $validated['total_size'],
            'total_chunks'     => $validated['total_chunks'],
            'sha256'           => $validated['sha256'] ?? null,
            'status'           => 'pending',
            'expires_at'       => now()->addDays(7),
        ]);

        return response()->json([
            'uuid'            => $session->uuid,
            'total_chunks'    => $session->total_chunks,
            'received_chunks' => 0,
            'status'          => 'pending',
        ], 201);
    }

    /**
     * Upload a single chunk.
     * PUT /api/v1/uploads/{uuid}/chunks/{index}
     */
    public function uploadChunk(Request $request, string $uuid, int $index): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($index < 0 || $index >= $session->total_chunks) {
            return response()->json(['error' => 'Invalid chunk index'], 422);
        }

        if (!$request->hasFile('chunk')) {
            return response()->json(['error' => 'No chunk data'], 422);
        }

        $file     = $request->file('chunk');
        $chunkDir = self::CHUNK_DIR . '/' . $session->uuid;
        $path     = $file->storeAs($chunkDir, "chunk_{$index}", self::CHUNK_DISK);

        // Checksum validation if provided
        $checksum = $request->input('checksum');
        if ($checksum && md5_file(Storage::disk(self::CHUNK_DISK)->path($path)) !== $checksum) {
            Storage::disk(self::CHUNK_DISK)->delete($path);
            return response()->json(['error' => 'Chunk checksum mismatch'], 422);
        }

        UploadChunk::updateOrCreate(
            ['upload_session_id' => $session->id, 'chunk_index' => $index],
            [
                'path'        => $path,
                'size_bytes'  => $file->getSize(),
                'checksum'    => $checksum,
                'status'      => 'received',
                'received_at' => now(),
            ]
        );

        $receivedCount = $session->chunks()->count();
        $session->update([
            'received_chunks' => $receivedCount,
            'uploaded_bytes'  => $session->chunks()->sum('size_bytes'),
        ]);

        return response()->json([
            'chunk_index'     => $index,
            'received_chunks' => $receivedCount,
            'total_chunks'    => $session->total_chunks,
            'complete'        => $receivedCount >= $session->total_chunks,
        ]);
    }

    /**
     * Get upload session status.
     * GET /api/v1/uploads/{uuid}
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->with('resultingMedia')
            ->firstOrFail();

        $receivedIndexes = $session->chunks()->pluck('chunk_index')->toArray();

        return response()->json([
            'uuid'             => $session->uuid,
            'status'           => $session->status,
            'total_chunks'     => $session->total_chunks,
            'received_chunks'  => $session->received_chunks,
            'uploaded_bytes'   => $session->uploaded_bytes,
            'total_size'       => $session->total_size,
            'received_indexes' => $receivedIndexes,
            'media_id'         => $session->resulting_media_id,
            'expires_at'       => $session->expires_at,
        ]);
    }

    /**
     * Finalize/complete upload session — triggers assembly job.
     * POST /api/v1/uploads/{uuid}/complete
     */
    public function complete(Request $request, string $uuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        if (!$session->isComplete()) {
            return response()->json([
                'error'           => 'Upload not complete',
                'received_chunks' => $session->received_chunks,
                'total_chunks'    => $session->total_chunks,
            ], 422);
        }

        // Assemble synchronously — no queue worker required
        try {
            $session->update(['status' => 'assembling']);

            $chunks   = $session->chunks()->orderBy('chunk_index')->get();
            $destDir  = storage_path("app/uploads/{$session->uuid}");
            @mkdir($destDir, 0755, true);
            $destPath = $destDir . '/' . $session->original_filename;

            $destHandle = fopen($destPath, 'wb');
            if (!$destHandle) throw new \RuntimeException("Cannot open output file: {$destPath}");

            foreach ($chunks as $chunk) {
                $chunkPath = Storage::disk('local')->path($chunk->path);
                if (!file_exists($chunkPath)) {
                    throw new \RuntimeException("Missing chunk #{$chunk->chunk_index}");
                }
                $src = fopen($chunkPath, 'rb');
                stream_copy_to_stream($src, $destHandle);
                fclose($src);
            }
            fclose($destHandle);

            $assembledSize = filesize($destPath);
            if ($assembledSize !== $session->total_size) {
                throw new \RuntimeException("Size mismatch: expected {$session->total_size}, got {$assembledSize}");
            }

            // Upload to Google Drive (synchronous, best-effort)
            $driveFileId       = null;
            $driveParentFolder = null;
            try {
                $connection = StorageConnection::whereHas(
                    'owner',
                    fn($q) => $q->whereHas(
                        'gallerySpaces',
                        fn($q2) => $q2->where('gallery_spaces.id', $session->gallery_space_id)
                    )
                )
                    ->where('provider', 'google_drive')
                    ->where('connection_status', 'healthy')
                    ->first();

                if ($connection) {
                    $provider = new GoogleDriveStorageProvider($connection);

                    // Determine target Drive folder (album folder or root)
                    $driveFolderId = null;
                    if ($session->target_album_id) {
                        $album = Album::find($session->target_album_id);
                        $driveFolderId = $album?->drive_folder_id;

                        // Create album Drive folder inline if missing
                        if (!$driveFolderId && $album) {
                            $parentId  = $album->parent?->drive_folder_id ?? $connection->root_folder_id;
                            if ($parentId) {
                                $folder        = $provider->createFolder($album->title, $parentId);
                                $driveFolderId = $folder['id'];
                                $album->update(['drive_folder_id' => $driveFolderId, 'sync_status' => 'synced']);
                            }
                        }
                    }
                    $driveFolderId = $driveFolderId ?? $connection->root_folder_id;

                    if ($driveFolderId) {
                        $driveFile         = $provider->upload($destPath, $session->original_filename, $driveFolderId, $session->mime_type);
                        $driveFileId       = $driveFile['id'];
                        $driveParentFolder = $driveFolderId;
                        Log::info('Media uploaded to Drive', ['file_id' => $driveFileId]);
                    }
                }
            } catch (\Throwable $driveEx) {
                // Drive upload is non-fatal — file is already on local disk
                Log::warning('Drive upload failed (non-fatal)', ['error' => $driveEx->getMessage()]);
            }

            $ext         = strtolower(pathinfo($session->original_filename, PATHINFO_EXTENSION));
            $formatSvc   = new \App\Services\Media\MediaFormatService();
            $isRaw       = \App\Services\Media\MediaFormatService::isRaw($ext);
            $isVideo     = \App\Services\Media\MediaFormatService::isVideo($ext);
            $mediaType   = ($isVideo || str_starts_with($session->mime_type, 'video/')) ? 'video' : 'photo';

            // For RAW files: extract embedded JPEG preview for thumbnailing
            $previewPath = null;
            if ($isRaw) {
                $previewPath = $formatSvc->extractRawPreview($destPath);
            }

            $media = MediaItem::create([
                'gallery_space_id'    => $session->gallery_space_id,
                'owner_user_id'       => $session->user_id,
                'uploaded_by'         => $session->user_id,
                'primary_album_id'    => $session->target_album_id,
                'drive_file_id'       => $driveFileId,
                'drive_parent_folder_id' => $driveParentFolder,
                'original_filename'   => $session->original_filename,
                'safe_filename'       => preg_replace('/[^a-zA-Z0-9._-]/', '_', $session->original_filename),
                'extension'           => $ext,
                'mime_type'           => $session->mime_type ?: ($isRaw ? \App\Services\Media\MediaFormatService::rawMime($ext) : 'application/octet-stream'),
                'media_type'          => $mediaType,
                'is_raw'              => $isRaw,
                'raw_format'          => $isRaw ? $ext : null,
                'size_bytes'          => $assembledSize,
                'sha256'              => $session->sha256,
                'status'              => 'ready',
                'uploaded_at'         => now(),
            ]);

            // Store original file under public storage so it can be served
            $relPath = "media/{$media->uuid}/original." . $media->extension;
            $stored = Storage::disk('public')->put(
                $relPath,
                fopen($destPath, 'rb'),
                'public'
            );
            if (!$stored) {
                throw new \RuntimeException("Failed to store file to public disk: {$relPath}");
            }

            // Register original variant
            $media->variants()->create([
                'type'       => 'original',
                'disk'       => 'public',
                'path'       => $relPath,
                'mime_type'  => $media->mime_type,
                'size_bytes' => $assembledSize,
                'width'      => null,
                'height'     => null,
            ]);

            // Generate thumbnail synchronously using GD/Imagick (no queue needed)
            // For RAW files: use extracted preview JPEG if available
            $thumbSource = $previewPath ?? $destPath;
            $this->generateThumbnail($media, $thumbSource);
            if ($previewPath) {
                @unlink($previewPath);
            }

            // Extract basic EXIF data synchronously (GPS + date + dimensions + panorama/live photo)
            if ($media->media_type === 'photo') {
                $this->extractBasicExif($media, $destPath, $formatSvc);
            }

            $session->update([
                'status'             => 'completed',
                'completed_at'       => now(),
                'resulting_media_id' => $media->id,
            ]);

            // Dispatch metadata + hash processing (async, best-effort)
            CalculateMediaHashesJob::dispatch($media->id)->onQueue('media');

            // Cleanup chunk files
            Storage::disk('local')->deleteDirectory("upload_chunks/{$session->uuid}");

            AuditLog::record('media.upload', $media, ['filename' => $media->original_filename]);

            // Notify other space members about new upload
            try {
                $space = \App\Models\GallerySpace::find($session->gallery_space_id);
                if ($space) {
                    \App\Notifications\GalleryNotification::notifySpace(
                        $space,
                        $session->user_id,
                        'media.added',
                        request()->user()?->name . ' přidal/a nové médium: ' . $session->original_filename,
                        "/media/{$media->uuid}",
                        ['media_uuid' => $media->uuid],
                    );
                }
            } catch (\Throwable) { /* non-fatal */
            }

            return response()->json([
                'uuid'     => $session->uuid,
                'status'   => 'completed',
                'media_id' => $media->id,
            ]);
        } catch (\Throwable $e) {
            $session->update(['status' => 'failed']);
            Log::error('Upload assembly failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Assembly failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate a JPEG thumbnail — tries Imagick first, then GD.
     * If both fail, creates a thumbnail alias pointing to the original.
     */
    private function generateThumbnail(MediaItem $media, string $sourcePath): void
    {
        $thumbRel = "media/{$media->uuid}/thumbnail.jpg";
        $size     = 400;

        // --- Try Imagick ---
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick($sourcePath);
                $im->setIteratorIndex(0);
                $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                $im->autoOrient();

                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                $min = min($w, $h);
                $im->cropImage($min, $min, (int)(($w - $min) / 2), (int)(($h - $min) / 2));
                $im->thumbnailImage($size, $size);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);

                $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_thumb_') . '.jpg';
                $im->writeImage($tmpPath);
                $im->destroy();

                $stored = Storage::disk('public')->put($thumbRel, fopen($tmpPath, 'rb'));
                @unlink($tmpPath);

                if ($stored) {
                    $media->variants()->create([
                        'type'       => 'thumbnail',
                        'disk'       => 'public',
                        'path'       => $thumbRel,
                        'mime_type'  => 'image/jpeg',
                        'size_bytes' => Storage::disk('public')->size($thumbRel),
                        'width'      => $size,
                        'height'     => $size,
                    ]);
                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('Imagick thumbnail failed, trying GD', ['media_id' => $media->id, 'error' => $e->getMessage()]);
            }
        }

        // --- Try GD ---
        if (extension_loaded('gd')) {
            try {
                $ext = strtolower($media->extension);
                $src = match ($ext) {
                    'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
                    'png'         => @imagecreatefrompng($sourcePath),
                    'webp'        => @imagecreatefromwebp($sourcePath),
                    'gif'         => @imagecreatefromgif($sourcePath),
                    default       => null,
                };

                if ($src) {
                    if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'])) {
                        $exif        = @exif_read_data($sourcePath);
                        $orientation = $exif['Orientation'] ?? 1;
                        if ($orientation === 6) {
                            $src = imagerotate($src, -90, 0);
                        } elseif ($orientation === 3) {
                            $src = imagerotate($src, 180, 0);
                        } elseif ($orientation === 8) {
                            $src = imagerotate($src, 90, 0);
                        }
                    }

                    $origW = imagesx($src);
                    $origH = imagesy($src);
                    $min   = min($origW, $origH);
                    $cropX = (int)(($origW - $min) / 2);
                    $cropY = (int)(($origH - $min) / 2);

                    $thumb = imagecreatetruecolor($size, $size);
                    imagecopyresampled($thumb, $src, 0, 0, $cropX, $cropY, $size, $size, $min, $min);
                    imagedestroy($src);

                    $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_thumb_') . '.jpg';
                    imagejpeg($thumb, $tmpPath, 85);
                    imagedestroy($thumb);

                    $stored = Storage::disk('public')->put($thumbRel, fopen($tmpPath, 'rb'), 'public');
                    @unlink($tmpPath);

                    if ($stored) {
                        $media->variants()->create([
                            'type'       => 'thumbnail',
                            'disk'       => 'public',
                            'path'       => $thumbRel,
                            'mime_type'  => 'image/jpeg',
                            'size_bytes' => Storage::disk('public')->size($thumbRel),
                            'width'      => $size,
                            'height'     => $size,
                        ]);
                        return;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('GD thumbnail failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);
            }
        }

        // --- Fallback: alias thumbnail = original ---
        // When neither Imagick nor GD work, point thumbnail at the original file
        // so the grid shows something instead of a blank placeholder.
        $originalVar = $media->variants()->where('type', 'original')->first();
        if ($originalVar) {
            $media->variants()->create([
                'type'       => 'thumbnail',
                'disk'       => $originalVar->disk,
                'path'       => $originalVar->path,
                'mime_type'  => $originalVar->mime_type,
                'size_bytes' => $originalVar->size_bytes,
                'width'      => null,
                'height'     => null,
            ]);
            Log::info('Thumbnail aliased to original (no GD/Imagick)', ['media_id' => $media->id]);
        }
    }

    /**
     * Extract GPS, date, dimensions via ExifExtractorService.
     * Also detects panorama/360° and Live Photo pairs.
     */
    private function extractBasicExif(
        MediaItem $media,
        string $sourcePath,
        ?\App\Services\Media\MediaFormatService $formatSvc = null
    ): void {
        $formatSvc ??= new \App\Services\Media\MediaFormatService();

        try {
            $exifSvc  = new \App\Services\ExifExtractorService();
            $data     = $exifSvc->extract($sourcePath);
            $rawExif  = $exifSvc->getRawExif($sourcePath);   // get the full raw EXIF for extended detection

            if ($data) {
                $media->update(array_filter($data, fn($v) => $v !== null));
            }

            // Panorama / 360° detection
            $panData = $formatSvc->detectPanorama(
                $rawExif ?? [],
                $media->width,
                $media->height
            );
            if ($panData['is_panorama'] || $panData['is_360']) {
                $media->update([
                    'is_panorama'         => $panData['is_panorama'],
                    'is_360'              => $panData['is_360'],
                    'panorama_projection' => $panData['panorama_projection'],
                ]);
            }

            // Live Photo / Motion Photo detection
            $liveData = $formatSvc->detectLivePhoto($rawExif ?? [], $media->extension);
            if ($liveData['is_motion_photo']) {
                $media->update([
                    'live_photo_content_id' => $liveData['content_id'],
                    'live_photo_role'       => $liveData['role'],
                ]);

                // Try to link with already-uploaded pair
                if ($liveData['content_id']) {
                    $formatSvc->linkLivePhotoPair(
                        $media->id,
                        $liveData['content_id'],
                        $liveData['role'],
                        $media->gallery_space_id
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('EXIF extraction failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cancel an upload session.
     * DELETE /api/v1/uploads/{uuid}
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Clean up chunks
        Storage::disk(self::CHUNK_DISK)->deleteDirectory(self::CHUNK_DIR . '/' . $session->uuid);
        $session->delete();

        return response()->json(['status' => 'cancelled']);
    }
}
