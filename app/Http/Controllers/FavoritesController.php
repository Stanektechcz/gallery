<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FavoritesController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $media = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            ->where('status', 'ready')
            ->where('is_favorite', true)
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('taken_at')
            ->paginate(60)
            ->through(fn($m) => $this->formatItem($m));

        return Inertia::render('Favorites/Index', [
            'media' => $media,
        ]);
    }

    public function toggle(Request $request, string $uuid): \Illuminate\Http\JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $request->user()->gallerySpaces()->first()->id)
            ->firstOrFail();

        $media->is_favorite = !$media->is_favorite;
        $media->save();

        return response()->json(['is_favorite' => $media->is_favorite]);
    }

    private function formatItem(MediaItem $m): array
    {
        return [
            'id'         => $m->id,
            'uuid'       => $m->uuid,
            'media_type' => $m->media_type,
            'taken_at'   => $m->taken_at?->toIso8601String(),
            'width'      => $m->width,
            'height'     => $m->height,
            'is_favorite' => $m->is_favorite,
            'rating'     => $m->rating,
            'display_title' => $m->display_title ?? $m->original_filename,
            'variants'   => $m->variants->map(fn($v) => [
                'type'           => $v->type,
                'url'            => asset('storage/' . $v->path),
                'dominant_color' => $v->dominant_color,
                'aspect_ratio'   => $v->aspect_ratio,
            ]),
        ];
    }
}
