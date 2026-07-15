<?php

namespace App\Http\Controllers;

use App\Http\Requests\Album\CreateAlbumRequest;
use App\Models\MediaItem;
use App\Http\Requests\Album\MoveAlbumRequest;
use App\Http\Requests\Album\UpdateAlbumRequest;
use App\Jobs\Drive\CreateDriveFolderJob;
use App\Jobs\Drive\MoveDriveFolderJob;
use App\Jobs\Drive\RenameDriveFolderJob;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Services\AlbumService;
use App\Services\Media\UnassignedAlbumSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AlbumController extends Controller
{
    public function __construct(private readonly AlbumService $albumService) {}

    public function create(Request $request): Response
    {
        $space = $request->user()->gallerySpaces()->first();

        // Pre-select parent when coming from a specific album page
        $parentUuid = $request->query('parent');
        $parent = $parentUuid
            ? Album::where('uuid', $parentUuid)->where('gallery_space_id', $space->id)->first()
            : null;

        // Build flat list of all albums for parent selector
        $allAlbums = Album::where('gallery_space_id', $space->id)
            ->whereNull('deleted_at')
            ->orderBy('materialized_path')
            ->get(['id', 'uuid', 'title', 'depth', 'materialized_path'])
            ->map(fn($a) => [
                'id'    => $a->id,
                'uuid'  => $a->uuid,
                'title' => str_repeat('— ', $a->depth) . $a->title,
            ]);

        return Inertia::render('Albums/Create', [
            'allAlbums'  => $allAlbums,
            'parentAlbum' => $parent ? ['id' => $parent->id, 'uuid' => $parent->uuid, 'title' => $parent->title] : null,
        ]);
    }

    public function index(Request $request, UnassignedAlbumSuggestionService $suggestions): Response
    {
        $space = $request->user()->gallerySpaces()->first();

        $albums = Album::with(['cover', 'children.cover'])
            ->where('gallery_space_id', $space->id)
            ->whereNull('parent_id')
            ->whereNull('deleted_at')
            ->orderBy('sort_mode')
            ->get();

        return Inertia::render('Albums/Index', [
            'albums'      => $albums,
            'gallerySpace' => $space,
            'albumSuggestions' => $suggestions->suggestions($space, $request->user()),
            'albumSuggestionsAvailable' => $suggestions->available(),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $album = Album::where('uuid', $uuid)
            ->with(['cover', 'places', 'tags', 'people'])
            ->firstOrFail();

        Gate::authorize('view', $album);

        $children = $album->children()
            ->with('cover')
            ->whereNull('deleted_at')
            ->orderBy('title')
            ->get();

        // Filtrace a třídění
        $sortBy  = $request->input('sort', 'taken_at');
        $sortDir = $request->input('dir', 'desc');
        $type    = $request->input('type');   // photo|video
        $search  = $request->input('search');

        $allowedSort = ['taken_at', 'uploaded_at', 'size_bytes', 'original_filename'];
        if (!in_array($sortBy, $allowedSort)) $sortBy = 'taken_at';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        // Smart albums: compute media from rules instead of album_media
        $space = $request->user()->gallerySpaces()->first();
        $isSmartAlbum = false;
        try {
            $isSmartAlbum = ($album->album_type === 'smart') && $album->smart_rules;
        } catch (\Throwable) { /* Migration not yet applied */
        }

        if ($isSmartAlbum) {
            $smartService = new \App\Services\Media\SmartAlbumService();
            $mediaQuery   = $smartService->buildQuery($album, $space->id)
                ->with(['variants', 'tags', 'people', 'places'])
                ->where('is_hidden', false)
                ->whereIn('status', ['ready', 'received']);

            if ($type)   $mediaQuery->where('media_type', $type);
            if ($search) $mediaQuery->where('original_filename', 'like', "%{$search}%");

            $media = $mediaQuery->orderBy($sortBy, $sortDir)->paginate(48)->withQueryString();
        } else {
            $mediaQuery = MediaItem::query()
                ->where(function ($q) use ($album) {
                    $q->where('primary_album_id', $album->id)
                        ->orWhereHas('albums', fn($q2) => $q2->where('albums.id', $album->id));
                })
                ->with(['variants', 'tags', 'people', 'places'])
                ->whereNull('trashed_at')
                ->where('is_hidden', false)
                ->whereIn('status', ['ready', 'received']);

            if ($type)   $mediaQuery->where('media_type', $type);
            if ($search) $mediaQuery->where('original_filename', 'like', "%{$search}%");

            $media = $mediaQuery->orderBy($sortBy, $sortDir)->paginate(48)->withQueryString();
        }

        // Serialize smart_rules for frontend
        $albumData                = $album->toArray();
        $albumData['album_type']  = $album->album_type ?? 'physical';
        $albumData['smart_rules'] = is_string($album->smart_rules)
            ? json_decode($album->smart_rules, true)
            : $album->smart_rules;

        return Inertia::render('Albums/Show', [
            'album'      => $albumData,
            'breadcrumb' => $album->breadcrumb,
            'children'   => $children,
            'media'      => $media,
            'filters'    => ['sort' => $sortBy, 'dir' => $sortDir, 'type' => $type, 'search' => $search],
        ]);
    }

    public function store(CreateAlbumRequest $request): \Illuminate\Http\RedirectResponse
    {
        $space = $request->user()->gallerySpaces()->first();

        $album = $this->albumService->create(
            space: $space,
            data: $request->validated(),
            user: $request->user()
        );

        // Queue Drive folder creation
        CreateDriveFolderJob::dispatch($album);

        return redirect()->route('albums.show', $album->uuid)
            ->with('success', 'Album bylo vytvořeno.');
    }

    public function update(UpdateAlbumRequest $request, string $uuid): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $album = Album::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $album);

        $data = $request->validated();

        if (isset($data['title']) && $data['title'] !== $album->title) {
            $album->update(['sync_status' => 'pending']);
            RenameDriveFolderJob::dispatch($album, $data['title']);
        }

        $updated = $this->albumService->update($album, $data, $request->user());

        if ($request->wantsJson()) {
            return response()->json(['album' => $updated]);
        }

        return back()->with('success', 'Album bylo upraveno.');
    }

    public function move(MoveAlbumRequest $request, string $uuid): \Illuminate\Http\JsonResponse
    {
        $album     = Album::where('uuid', $uuid)->firstOrFail();
        $newParent = $request->input('parent_id')
            ? Album::findOrFail($request->input('parent_id'))
            : null;

        Gate::authorize('update', $album);
        if ($newParent) Gate::authorize('update', $newParent);

        $album->moveTo($newParent?->id);

        // Queue Drive move
        MoveDriveFolderJob::dispatch($album, $newParent?->drive_folder_id);

        return response()->json(['status' => 'moved', 'album' => $album->fresh()]);
    }

    public function destroy(string $uuid): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $album = Album::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('delete', $album);

        $this->albumService->softDelete($album, request()->user());

        if (request()->wantsJson()) {
            return response()->json(['status' => 'deleted']);
        }
        return redirect()->route('albums.index')->with('success', 'Album bylo smazáno.');
    }

    public function tree(Request $request): \Illuminate\Http\JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();

        $albums = Album::where('gallery_space_id', $space->id)
            ->whereNull('deleted_at')
            ->select(['id', 'uuid', 'parent_id', 'title', 'slug', 'depth', 'sort_mode', 'icon', 'color', 'media_count', 'descendant_count', 'sync_status'])
            ->orderBy('depth')
            ->orderBy('title')
            ->get();

        return response()->json($this->albumService->buildTree($albums));
    }
}
