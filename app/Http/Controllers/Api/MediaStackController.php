<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaStack;
use App\Services\Media\AutoStackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaStackController extends Controller
{
    public function preview(Request $request, AutoStackService $service): JsonResponse
    {
        $groups = $service->candidates($this->spaceId($request));

        return response()->json(['count' => $groups->count(), 'groups' => $groups]);
    }

    public function apply(Request $request, AutoStackService $service): JsonResponse
    {
        $data = $request->validate([
            'candidate_keys' => ['nullable', 'array', 'max:100'],
            'candidate_keys.*' => ['string', 'size:64'],
        ]);
        $stacks = $service->apply($this->spaceId($request), $request->user()->id, $data['candidate_keys'] ?? null);

        return response()->json(['created' => $stacks->count(), 'stacks' => $stacks], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->stack($request, $uuid)->load(['items.variants', 'cover']));
    }

    public function setCover(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['media_id' => ['required', 'integer']]);
        $stack = $this->stack($request, $uuid);
        abort_unless($stack->items()->where('media_items.id', $data['media_id'])->exists(), 422, 'Fotografie není součástí stacku.');

        DB::transaction(function () use ($stack, $data) {
            DB::table('media_stack_items')->where('media_stack_id', $stack->id)->update(['is_cover' => false]);
            DB::table('media_stack_items')->where('media_stack_id', $stack->id)->where('media_item_id', $data['media_id'])->update(['is_cover' => true]);
            $stack->update(['cover_media_id' => $data['media_id']]);
        });

        return response()->json($stack->fresh(['items.variants', 'cover']));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->stack($request, $uuid)->delete();

        return response()->json(null, 204);
    }

    private function stack(Request $request, string $uuid): MediaStack
    {
        return MediaStack::where('gallery_space_id', $this->spaceId($request))->where('uuid', $uuid)->firstOrFail();
    }

    private function spaceId(Request $request): int
    {
        return (int) $request->user()->gallerySpaces()->value('gallery_spaces.id');
    }
}
