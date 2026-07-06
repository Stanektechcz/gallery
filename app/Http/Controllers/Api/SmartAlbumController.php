<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\MediaItem;
use App\Services\Media\SmartAlbumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SmartAlbumController extends Controller
{
    public function __construct(private SmartAlbumService $svc) {}

    /**
     * GET /api/v1/albums/{uuid}/smart-rules
     */
    public function getRules(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolve($uuid, $request);
        return response()->json([
            'album_type'  => $album->album_type ?? 'physical',
            'smart_rules' => is_string($album->smart_rules)
                ? json_decode($album->smart_rules, true)
                : ($album->smart_rules ?? ['match' => 'all', 'conditions' => []]),
        ]);
    }

    /**
     * PUT /api/v1/albums/{uuid}/smart-rules
     * Update smart album type + rules.
     */
    public function updateRules(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolve($uuid, $request);

        $v = $request->validate([
            'album_type'              => 'required|in:physical,smart',
            'smart_rules'             => 'nullable|array',
            'smart_rules.match'       => 'nullable|in:all,any',
            'smart_rules.conditions'  => 'nullable|array',
            'smart_rules.conditions.*.field' => 'required|string',
            'smart_rules.conditions.*.op'    => 'required|string',
            'smart_rules.conditions.*.value' => 'nullable',
        ]);

        DB::table('albums')->where('id', $album->id)->update([
            'album_type'  => $v['album_type'],
            'smart_rules' => isset($v['smart_rules']) ? json_encode($v['smart_rules']) : null,
            'updated_at'  => now(),
        ]);

        return response()->json(['status' => 'saved', 'album_type' => $v['album_type']]);
    }

    /**
     * GET /api/v1/albums/{uuid}/smart-preview
     * Preview how many items match the current rules.
     */
    public function preview(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolve($uuid, $request);
        $space = $request->user()->gallerySpaces()->first();

        if (($album->album_type ?? 'physical') !== 'smart' || ! $album->smart_rules) {
            return response()->json(['count' => 0, 'samples' => []]);
        }

        $q       = $this->svc->buildQuery($album, $space->id);
        $count   = $q->count();
        $samples = MediaItem::with('variants')
            ->whereIn('id', (clone $q)->orderByDesc('taken_at')->select('id')->limit(6)->pluck('id'))
            ->get()
            ->map(fn($m) => ['uuid' => $m->uuid, 'thumbnail_url' => $m->thumbnail_url]);

        return response()->json(['count' => $count, 'samples' => $samples]);
    }

    private function resolve(string $uuid, Request $request): Album
    {
        $space = $request->user()->gallerySpaces()->first();
        return Album::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->firstOrFail();
    }
}
