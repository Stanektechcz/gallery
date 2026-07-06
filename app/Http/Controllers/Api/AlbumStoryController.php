<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlbumStoryController extends Controller
{
    private const ALLOWED_TYPES = ['heading', 'text', 'quote', 'photo', 'video', 'map', 'divider'];

    /**
     * GET /api/v1/albums/{uuid}/story
     */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolveAlbum($uuid, $request);

        $blocks = DB::table('album_story_blocks')
            ->where('album_id', $album->id)
            ->orderBy('sort_order')
            ->get();

        // Resolve media for photo/video blocks
        $enriched = $blocks->map(fn($b) => $this->enrichBlock($b));

        return response()->json($enriched->values());
    }

    /**
     * POST /api/v1/albums/{uuid}/story
     */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolveAlbum($uuid, $request);

        $v = $request->validate([
            'type'        => 'required|in:' . implode(',', self::ALLOWED_TYPES),
            'content'     => 'nullable|array',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $maxOrder = DB::table('album_story_blocks')
            ->where('album_id', $album->id)
            ->max('sort_order') ?? -1;

        $id = DB::table('album_story_blocks')->insertGetId([
            'album_id'   => $album->id,
            'created_by' => $request->user()->id,
            'type'       => $v['type'],
            'content'    => json_encode($v['content'] ?? []),
            'sort_order' => $v['sort_order'] ?? $maxOrder + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->enrichBlock(DB::table('album_story_blocks')->find($id)), 201);
    }

    /**
     * PATCH /api/v1/albums/{uuid}/story/{blockId}
     */
    public function update(Request $request, string $uuid, int $blockId): JsonResponse
    {
        $album = $this->resolveAlbum($uuid, $request);

        $v = $request->validate([
            'content'    => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $update = ['updated_at' => now()];
        if (array_key_exists('content', $v)) {
            $update['content'] = json_encode($v['content'] ?? []);
        }
        if (array_key_exists('sort_order', $v)) {
            $update['sort_order'] = $v['sort_order'];
        }

        DB::table('album_story_blocks')
            ->where('id', $blockId)
            ->where('album_id', $album->id)
            ->update($update);

        return response()->json($this->enrichBlock(DB::table('album_story_blocks')->find($blockId)));
    }

    /**
     * DELETE /api/v1/albums/{uuid}/story/{blockId}
     */
    public function destroy(Request $request, string $uuid, int $blockId): JsonResponse
    {
        $album = $this->resolveAlbum($uuid, $request);

        DB::table('album_story_blocks')
            ->where('id', $blockId)
            ->where('album_id', $album->id)
            ->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * PUT /api/v1/albums/{uuid}/story/reorder
     * Body: { order: [id1, id2, id3, ...] }
     */
    public function reorder(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolveAlbum($uuid, $request);

        $v = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        foreach ($v['order'] as $i => $blockId) {
            DB::table('album_story_blocks')
                ->where('id', $blockId)
                ->where('album_id', $album->id)
                ->update(['sort_order' => $i, 'updated_at' => now()]);
        }

        return response()->json(['reordered' => count($v['order'])]);
    }

    /**
     * PATCH /api/v1/albums/{uuid}/story-mode
     * Toggle story_mode on the album.
     */
    public function toggleStoryMode(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolveAlbum($uuid, $request);

        $v = $request->validate(['story_mode' => 'required|boolean']);
        $album->update(['story_mode' => $v['story_mode']]);

        return response()->json(['story_mode' => $album->story_mode]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function resolveAlbum(string $uuid, Request $request): Album
    {
        $space = $request->user()->gallerySpaces()->first();
        return Album::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->firstOrFail();
    }

    private function enrichBlock(object $block): array
    {
        $content = is_string($block->content)
            ? (json_decode($block->content, true) ?? [])
            : ($block->content ?? []);

        $result = [
            'id'         => $block->id,
            'type'       => $block->type,
            'content'    => $content,
            'sort_order' => $block->sort_order,
            'created_at' => $block->created_at,
        ];

        // Resolve photo block media
        if ($block->type === 'photo' && ! empty($content['media_uuids'])) {
            $items = MediaItem::with('variants')
                ->whereIn('uuid', $content['media_uuids'])
                ->get();

            $result['media'] = $items->map(fn($m) => [
                'uuid'      => $m->uuid,
                'thumb_url' => $m->thumbnail_url,
                'full_url'  => "/media/{$m->uuid}/full",
            ])->toArray();
        }

        // Resolve video block media
        if ($block->type === 'video' && ! empty($content['media_uuid'])) {
            $item = MediaItem::with('variants')
                ->where('uuid', $content['media_uuid'])
                ->first();
            if ($item) {
                $poster = $item->getVariant('video_poster') ?? $item->getVariant('thumbnail');
                $result['media'] = [[
                    'uuid'       => $item->uuid,
                    'thumb_url'  => $item->thumbnail_url,
                    'stream_url' => "/media/{$item->uuid}/stream",
                    'poster_url' => $poster?->url,
                ]];
            }
        }

        return $result;
    }
}
