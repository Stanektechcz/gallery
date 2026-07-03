<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\UploadChunk;
use App\Models\UploadSession;
use App\Jobs\Upload\AssembleUploadChunksJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    private const CHUNK_DISK = 'local';
    private const CHUNK_DIR  = 'upload_chunks';

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
            'original_filename'=> $validated['filename'],
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

        $session->update(['status' => 'assembling']);
        AssembleUploadChunksJob::dispatch($session);

        return response()->json([
            'uuid'   => $session->uuid,
            'status' => 'assembling',
        ]);
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
