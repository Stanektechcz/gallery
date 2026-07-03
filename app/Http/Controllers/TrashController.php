<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        if ($media->drive_file_id) {
            \App\Jobs\Media\PurgeMediaFromDriveJob::dispatch($media)->onQueue('drive');
        }

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
            if ($item->drive_file_id) {
                \App\Jobs\Media\PurgeMediaFromDriveJob::dispatch($item)->onQueue('drive');
            }
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
                'url'            => asset('storage/' . $v->path),
                'dominant_color' => $v->dominant_color,
                'aspect_ratio'   => $v->aspect_ratio,
            ]),
        ];
    }
}
