<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\Tag;
use App\Models\User;
use App\Services\Taxonomy\UniversalTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function __construct(private readonly UniversalTagService $universalTags) {}

    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request->user());
        $genericCounts = Schema::hasTable('tag_assignments')
            ? DB::table('tag_assignments')->selectRaw('tag_id, count(*) as aggregate')->groupBy('tag_id')->pluck('aggregate', 'tag_id')
            : collect();
        $tags = Tag::where('gallery_space_id', $space->id)->withCount(['media', 'albums'])->orderBy('name')->get();
        $tags->each(function (Tag $tag) use ($genericCounts): void {
            $tag->setAttribute('connections_count', (int) $tag->media_count + (int) $tag->albums_count + (int) ($genericCounts[$tag->id] ?? 0));
        });

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'parent_id' => 'nullable|integer', 'color' => 'nullable|string|max:20']);
        $space = $this->space($request->user());
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) Tag::where('gallery_space_id', $space->id)->whereKey($parentId)->firstOrFail();
        $slug = Str::slug($data['name']);
        abort_if(Tag::where('gallery_space_id', $space->id)->where('slug', $slug)->exists(), 422, 'Tento štítek už v prostoru existuje.');
        $tag = Tag::create([
            ...$data,
            'gallery_space_id' => $space->id,
            'slug' => $slug,
            'depth' => $parentId ? 1 : 0,
            'materialized_path' => '',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($tag, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->tag($request->user(), $id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tag = $this->tag($request->user(), $id);
        $data = $request->validate(['name' => 'sometimes|string|max:100', 'color' => 'nullable|string|max:20', 'parent_id' => 'nullable|integer']);
        if (array_key_exists('parent_id', $data) && $data['parent_id']) {
            abort_if((int) $data['parent_id'] === (int) $tag->id, 422, 'Štítek nemůže být vlastním rodičem.');
            Tag::where('gallery_space_id', $tag->gallery_space_id)->whereKey($data['parent_id'])->firstOrFail();
        }
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
            abort_if(Tag::where('gallery_space_id', $tag->gallery_space_id)->where('slug', $data['slug'])->where('id', '!=', $tag->id)->exists(), 422, 'Tento štítek už v prostoru existuje.');
        }
        $tag->update($data);

        return response()->json($tag->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->tag($request->user(), $id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function connections(Request $request, int $id): JsonResponse
    {
        $tag = $this->tag($request->user(), $id);
        return response()->json(['tag' => $tag, 'connections' => $this->universalTags->connections($tag, $request->user())]);
    }

    public function attach(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['entity_type' => 'required|string|max:48', 'entity_id' => 'required|integer|min:1']);
        $user = $request->user();
        $tag = $this->tag($user, $id);
        $this->universalTags->attach($tag, $this->space($user), $user, $data['entity_type'], (int) $data['entity_id']);

        return response()->json(['status' => 'attached']);
    }

    public function detach(Request $request, int $id, string $entityType, int $entityId): JsonResponse
    {
        $user = $request->user();
        $this->universalTags->detach($this->tag($user, $id), $this->space($user), $user, $entityType, $entityId);
        return response()->json(['status' => 'detached']);
    }

    private function space(User $user): GallerySpace
    {
        return $user->gallerySpaces()->orderByDesc('is_default')->firstOrFail();
    }

    private function tag(User $user, int $id): Tag
    {
        return Tag::where('gallery_space_id', $this->space($user)->id)->findOrFail($id);
    }
}