<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArchiveController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $media = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_archived', true)
            ->where('status', 'ready')
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('taken_at')
            ->paginate(60)
            ->through(fn($m) => $this->formatItem($m));

        return Inertia::render('Archive/Index', [
            'media' => $media,
        ]);
    }

    public function unarchive(Request $request, string $uuid): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $media = MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->firstOrFail();

        $media->update(['is_archived' => false]);
        AuditLog::record('media.unarchive', $media);

        return response()->json(['status' => 'unarchived', 'uuid' => $uuid]);
    }

    public function bulkUnarchive(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $uuids = $request->validate(['uuids' => 'required|array|max:200', 'uuids.*' => 'string'])['uuids'];

        $count = MediaItem::where('gallery_space_id', $space->id)
            ->whereIn('uuid', $uuids)
            ->update(['is_archived' => false]);

        return response()->json(['count' => $count]);
    }

    private function formatItem(MediaItem $m): array
    {
        return [
            'id'           => $m->id,
            'uuid'         => $m->uuid,
            'media_type'   => $m->media_type,
            'taken_at'     => $m->taken_at?->toIso8601String(),
            'width'        => $m->width,
            'height'       => $m->height,
            'is_favorite'  => $m->is_favorite,
            'display_title' => $m->display_title ?? $m->original_filename,
            'variants'     => $m->variants->map(fn($v) => [
                'type'           => $v->type,
                'url'            => asset('storage/' . $v->path),
                'dominant_color' => $v->dominant_color,
                'aspect_ratio'   => $v->aspect_ratio,
            ]),
        ];
    }
}
