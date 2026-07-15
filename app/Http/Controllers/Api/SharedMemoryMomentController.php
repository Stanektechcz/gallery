<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SharedMemoryMomentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $spaceIds = $request->user()->gallerySpaces()->pluck('gallery_spaces.id');
        $items = DB::table('shared_memory_moments')->whereIn('gallery_space_id', $spaceIds)->orderByDesc('happened_on')->latest()->get();
        return response()->json($items->map(fn ($item) => $this->payload($item, $request->user()->id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'required|string|max:160', 'note' => 'nullable|string|max:5000', 'happened_on' => 'nullable|date', 'media_item_ids' => 'nullable|array|max:30', 'media_item_ids.*' => 'integer|distinct', 'is_favorite' => 'nullable|boolean']);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        $mediaIds = $data['media_item_ids'] ?? [];
        abort_unless(count($mediaIds) === MediaItem::whereIn('id', $mediaIds)->where('gallery_space_id', $data['gallery_space_id'])->whereNull('trashed_at')->count(), 422, 'Do společné vzpomínky lze přidat jen média z daného prostoru.');
        $id = DB::table('shared_memory_moments')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $data['gallery_space_id'], 'created_by' => $request->user()->id, 'title' => $data['title'], 'note' => $data['note'] ?? null, 'happened_on' => $data['happened_on'] ?? null, 'media_item_ids' => json_encode($mediaIds), 'is_favorite' => $data['is_favorite'] ?? false, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->payload(DB::table('shared_memory_moments')->find($id), $request->user()->id), 201);
    }

    public function upsertReflection(Request $request, string $uuid): JsonResponse
    {
        abort_unless(Schema::hasTable('shared_memory_reflections'), 503, 'Pro partnerské pohledy dokončete migrace aplikace.');
        $item = $this->visibleMoment($request, $uuid);
        $data = $request->validate(['mood' => 'nullable|in:joyful,calm,adventurous,cozy,grateful,funny', 'note' => 'nullable|string|max:3000']);
        abort_if(empty($data['mood']) && trim((string) ($data['note'] ?? '')) === '', 422, 'Vyberte náladu nebo napište svůj pohled.');

        $existing = DB::table('shared_memory_reflections')->where('shared_memory_moment_id', $item->id)->where('user_id', $request->user()->id)->first();
        $row = ['mood' => $data['mood'] ?? null, 'note' => trim((string) ($data['note'] ?? '')) ?: null, 'updated_at' => now()];
        if ($existing) DB::table('shared_memory_reflections')->where('id', $existing->id)->update($row);
        else DB::table('shared_memory_reflections')->insert($row + ['shared_memory_moment_id' => $item->id, 'user_id' => $request->user()->id, 'created_at' => now()]);

        return response()->json($this->payload($item, $request->user()->id), $existing ? 200 : 201);
    }

    public function destroyReflection(Request $request, string $uuid): JsonResponse
    {
        abort_unless(Schema::hasTable('shared_memory_reflections'), 503, 'Pro partnerské pohledy dokončete migrace aplikace.');
        $item = $this->visibleMoment($request, $uuid);
        DB::table('shared_memory_reflections')->where('shared_memory_moment_id', $item->id)->where('user_id', $request->user()->id)->delete();
        return response()->json($this->payload($item, $request->user()->id));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $item = $this->visibleMoment($request, $uuid);
        abort_unless($item->created_by === $request->user()->id, 403);
        DB::table('shared_memory_moments')->where('id', $item->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    private function visibleMoment(Request $request, string $uuid): object
    {
        return DB::table('shared_memory_moments')->where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
    }

    private function payload(object $item, ?int $viewerId = null): array
    {
        $ids = array_values(array_filter(json_decode($item->media_item_ids ?: '[]', true) ?: [], 'is_numeric'));
        $media = MediaItem::whereIn('id', $ids)->with('variants')->get()->map(fn (MediaItem $media) => ['uuid' => $media->uuid, 'title' => $media->display_title ?: $media->original_filename, 'thumbnail_url' => $media->thumbnail_url]);
        $directAlbum = ! empty($item->album_id)
            ? DB::table('albums')->where('id', $item->album_id)->where('gallery_space_id', $item->gallery_space_id)->whereNull('deleted_at')->first(['uuid', 'title'])
            : null;
        $source = !empty($item->calendar_event_id)
            ? DB::table('calendar_events')
                ->leftJoin('albums', 'albums.id', '=', 'calendar_events.album_id')
                ->where('calendar_events.id', $item->calendar_event_id)
                ->where('calendar_events.gallery_space_id', $item->gallery_space_id)
                ->first(['calendar_events.uuid as event_uuid', 'calendar_events.title as event_title', 'albums.uuid as album_uuid', 'albums.title as album_title'])
            : null;
        $tripAlbum = (! $source?->album_uuid && ! empty($item->trip_id) && Schema::hasColumn('albums', 'trip_id'))
            ? DB::table('albums')->where('trip_id', $item->trip_id)->where('gallery_space_id', $item->gallery_space_id)->whereNull('deleted_at')->first(['uuid', 'title'])
            : null;
        $reflections = Schema::hasTable('shared_memory_reflections')
            ? DB::table('shared_memory_reflections as reflection')
                ->join('users', 'users.id', '=', 'reflection.user_id')
                ->where('reflection.shared_memory_moment_id', $item->id)
                ->orderBy('reflection.created_at')
                ->get(['reflection.user_id', 'users.name as user_name', 'reflection.mood', 'reflection.note', 'reflection.created_at', 'reflection.updated_at'])
                ->map(fn ($reflection) => (array) $reflection + ['is_mine' => $viewerId !== null && (int) $reflection->user_id === $viewerId])
                ->values()
            : collect();

        return [
            'uuid' => $item->uuid,
            'gallery_space_id' => $item->gallery_space_id,
            'created_by' => $item->created_by,
            'trip_id' => $item->trip_id ?? null,
            'calendar_event' => $source?->event_uuid ? ['uuid' => $source->event_uuid, 'title' => $source->event_title] : null,
            'album' => $directAlbum
                ? ['uuid' => $directAlbum->uuid, 'title' => $directAlbum->title]
                : ($source?->album_uuid
                ? ['uuid' => $source->album_uuid, 'title' => $source->album_title]
                : ($tripAlbum ? ['uuid' => $tripAlbum->uuid, 'title' => $tripAlbum->title] : null)),
            'title' => $item->title,
            'note' => $item->note,
            'happened_on' => $item->happened_on,
            'is_favorite' => (bool) $item->is_favorite,
            'media' => $media,
            'reflections' => $reflections,
            'created_at' => $item->created_at,
        ];
    }
}
