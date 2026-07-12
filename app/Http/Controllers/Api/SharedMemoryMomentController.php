<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SharedMemoryMomentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $spaceIds = $request->user()->gallerySpaces()->pluck('gallery_spaces.id');
        $items = DB::table('shared_memory_moments')->whereIn('gallery_space_id', $spaceIds)->orderByDesc('happened_on')->latest()->get();
        return response()->json($items->map(fn ($item) => $this->payload($item)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'required|string|max:160', 'note' => 'nullable|string|max:5000', 'happened_on' => 'nullable|date', 'media_item_ids' => 'nullable|array|max:30', 'media_item_ids.*' => 'integer|distinct', 'is_favorite' => 'nullable|boolean']);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        $mediaIds = $data['media_item_ids'] ?? [];
        abort_unless(count($mediaIds) === MediaItem::whereIn('id', $mediaIds)->where('gallery_space_id', $data['gallery_space_id'])->whereNull('trashed_at')->count(), 422, 'Do společné vzpomínky lze přidat jen média z daného prostoru.');
        $id = DB::table('shared_memory_moments')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $data['gallery_space_id'], 'created_by' => $request->user()->id, 'title' => $data['title'], 'note' => $data['note'] ?? null, 'happened_on' => $data['happened_on'] ?? null, 'media_item_ids' => json_encode($mediaIds), 'is_favorite' => $data['is_favorite'] ?? false, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->payload(DB::table('shared_memory_moments')->find($id)), 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $item = DB::table('shared_memory_moments')->where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
        abort_unless($item->created_by === $request->user()->id, 403);
        DB::table('shared_memory_moments')->where('id', $item->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    private function payload(object $item): array
    {
        $ids = array_values(array_filter(json_decode($item->media_item_ids ?: '[]', true) ?: [], 'is_numeric'));
        $media = MediaItem::whereIn('id', $ids)->with('variants')->get()->map(fn (MediaItem $media) => ['uuid' => $media->uuid, 'title' => $media->display_title ?: $media->original_filename, 'thumbnail_url' => $media->thumbnail_url]);
        return ['uuid' => $item->uuid, 'gallery_space_id' => $item->gallery_space_id, 'created_by' => $item->created_by, 'title' => $item->title, 'note' => $item->note, 'happened_on' => $item->happened_on, 'is_favorite' => (bool) $item->is_favorite, 'media' => $media, 'created_at' => $item->created_at];
    }
}
