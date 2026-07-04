<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $media = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNotNull('trashed_at')
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('trashed_at')
            ->paginate(60)
            ->through(fn($m) => $this->formatItem($m));

        $retentionDays = config('gallery.trash_retention_days', 30);

        return Inertia::render('Trash/Index', [
            'media'          => $media,
            'retention_days' => $retentionDays,
            'can_purge'      => $user->isAdmin(),
        ]);
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $media = MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->whereNotNull('trashed_at')
            ->firstOrFail();

        $media->update(['trashed_at' => null, 'purge_after' => null]);
        AuditLog::record('media.restore', $media);

        return response()->json(['status' => 'restored', 'uuid' => $uuid]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $uuids = $request->validate(['uuids' => 'required|array|max:200', 'uuids.*' => 'string'])['uuids'];

        $count = MediaItem::where('gallery_space_id', $space->id)
            ->whereIn('uuid', $uuids)
            ->whereNotNull('trashed_at')
            ->update(['trashed_at' => null, 'purge_after' => null]);

        return response()->json(['count' => $count]);
    }

    public function purge(Request $request, string $uuid): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            abort(403, 'Trvalé smazání vyžaduje admin oprávnění.');
        }

        $space = $request->user()->gallerySpaces()->first();
        $media = MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->firstOrFail();

        AuditLog::record('media.purge', $media, ['filename' => $media->original_filename]);

        $this->deleteMediaFiles($media);
        $media->delete();

        return response()->json(['status' => 'purged', 'uuid' => $uuid]);
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }

        $space = $request->user()->gallerySpaces()->first();
        $items = MediaItem::where('gallery_space_id', $space->id)
            ->whereNotNull('trashed_at')
            ->get();

        foreach ($items as $item) {
            AuditLog::record('media.purge', $item, ['via' => 'empty_trash']);
            $this->deleteMediaFiles($item);
            $item->delete();
        }

        return response()->json(['count' => $items->count()]);
    }

    private function formatItem(MediaItem $m): array
    {
        return [
            'id'           => $m->id,
            'uuid'         => $m->uuid,
            'media_type'   => $m->media_type,
            'taken_at'     => $m->taken_at?->toIso8601String(),
            'trashed_at'   => $m->trashed_at?->toIso8601String(),
            'purge_after'  => $m->purge_after?->toIso8601String(),
            'width'        => $m->width,
            'height'       => $m->height,
            'display_title' => $m->display_title ?? $m->original_filename,
            'size_bytes'   => $m->size_bytes,
            'variants'     => $m->variants->map(fn($v) => [
                'type'           => $v->type,
                'url'            => $v->url,
                'dominant_color' => $v->dominant_color,
                'aspect_ratio'   => $v->aspect_ratio,
            ]),
        ];
    }

    private function deleteMediaFiles(MediaItem $media): void
    {
        // Delete all local variant files
        foreach ($media->variants as $variant) {
            if ($variant->disk === 'public' && $variant->path) {
                try {
                    Storage::disk('public')->delete($variant->path);
                } catch (\Throwable $e) {
                    Log::warning('Could not delete variant file', ['path' => $variant->path, 'error' => $e->getMessage()]);
                }
            }
        }

        // Delete the media directory (media/{uuid}/)
        try {
            Storage::disk('public')->deleteDirectory("media/{$media->uuid}");
        } catch (\Throwable $e) {
            Log::warning('Could not delete media directory', ['uuid' => $media->uuid]);
        }

        // Delete assembled source file
        $assembledDir = storage_path("app/uploads/{$media->uuid}");
        if (is_dir($assembledDir)) {
            array_map('unlink', glob("$assembledDir/*") ?: []);
            @rmdir($assembledDir);
        }

        // Delete from Google Drive (synchronous, best-effort)
        if ($media->drive_file_id) {
            try {
                $connection = StorageConnection::whereHas(
                    'owner',
                    fn($q) => $q->whereHas('gallerySpaces', fn($q2) => $q2->where('gallery_spaces.id', $media->gallery_space_id))
                )->where('provider', 'google_drive')->where('connection_status', 'healthy')->first();

                if ($connection) {
                    $provider = new GoogleDriveStorageProvider($connection);
                    $provider->trash($media->drive_file_id);
                }
            } catch (\Throwable $e) {
                Log::warning('Could not trash Drive file', ['drive_id' => $media->drive_file_id, 'error' => $e->getMessage()]);
            }
        }
    }
}
