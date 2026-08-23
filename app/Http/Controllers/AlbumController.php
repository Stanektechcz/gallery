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

        // cover.variants, not cover. The card looks for the thumbnail among the cover's
        // variants, and loading the media row without them meant every album drew the
        // folder placeholder — including one with fifty-two photographs in it, and
        // including albums whose cover had just been chosen by hand.
        $albums = Album::with(['cover.variants', 'children.cover.variants'])
            ->where('gallery_space_id', $space->id)
            ->whereNull('parent_id')
            ->whereNull('deleted_at')
            // Podle názvu, ne podle `sort_mode`. To je slovo popisující, jak se řadí
            // obsah alba — „date_taken", „manual" — takže se alba seřadila abecedně
            // podle svého nastavení, což je pořadí bez jakéhokoli významu pro čtenáře.
            ->orderBy('title')
            ->get();

        $this->fillMissingCovers($albums);

        return Inertia::render('Albums/Index', [
            'albums'      => $albums,
            'gallerySpace' => $space,
            'albumSuggestions' => $suggestions->suggestions($space, $request->user()),
            'albumSuggestionsAvailable' => $suggestions->available(),
        ]);
    }

    /**
     * Shows something for albums nobody has chosen a cover for.
     *
     * An album holding fifty photographs drew a folder icon, which said less about it
     * than any one of the pictures inside would have. Choosing a cover by hand is still
     * better and still wins; this is only for the albums nobody got round to.
     *
     * Nothing is written. The album keeps no cover of its own, so the day somebody picks
     * one it simply takes over.
     *
     * One query for all of them rather than one each, because this runs on a page that
     * lists every album a space has.
     *
     * @param \Illuminate\Support\Collection<int, Album> $albums
     */
    private function fillMissingCovers($albums): void
    {
        $ploche = $albums->concat($albums->flatMap->children ?? collect());
        $chybi = $ploche->filter(fn (Album $album) => ! $album->cover && $album->media_count > 0);

        if ($chybi->isEmpty()) return;

        // The best picture in each album rather than merely the newest, ordered the same
        // way the album assistant already ranks its own recommendation: something the
        // person marked as a favourite, then whatever they rated, then a photograph over
        // a still from a film, and only then the sharpest of what is left.
        //
        // A hand-picked cover still beats all of it — these albums have none.
        $nejlepsi = \Illuminate\Support\Facades\DB::table('album_media')
            ->join('media_items', 'media_items.id', '=', 'album_media.media_item_id')
            ->whereIn('album_media.album_id', $chybi->pluck('id'))
            ->whereNull('media_items.trashed_at')
            ->orderByDesc('media_items.is_favorite')
            ->orderByRaw('COALESCE(media_items.rating, 0) DESC')
            ->orderByRaw("CASE WHEN media_items.media_type = 'photo' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(media_items.width, 0) * COALESCE(media_items.height, 0) DESC')
            ->orderByDesc('media_items.taken_at')
            ->get(['album_media.album_id', 'media_items.id as media_id'])
            ->unique('album_id')
            ->keyBy('album_id');

        $media = MediaItem::with('variants')
            ->whereIn('id', $nejlepsi->pluck('media_id'))
            ->get()
            ->keyBy('id');

        foreach ($chybi as $album) {
            $vybrane = $nejlepsi->get($album->id);
            if ($vybrane && $media->has($vybrane->media_id)) {
                $album->setRelation('cover', $media->get($vybrane->media_id));
            }
        }
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

        // Filtrace a třídění.
        //
        // Výchozí hodnota je ta, kterou si album uložilo ve svém nastavení — jinak
        // volba „Výchozí řazení" nedělala vůbec nic a album se vždycky otevřelo podle
        // data pořízení, ať si člověk nastavil cokoli. Adresa v URL má pořád přednost,
        // aby šlo řazení přepnout jen pro tenhle pohled.
        $albumSort = match ($album->sort_mode) {
            'date_uploaded' => 'uploaded_at',
            'title' => 'original_filename',
            // Ruční pořadí drží album_media.sort_order, ne sloupec na médiu; dokud
            // neexistuje obrazovka na přeskládání, chová se jako datum pořízení.
            default => 'taken_at',
        };

        $sortBy  = $request->input('sort', $albumSort);
        $sortDir = $request->input('dir', $album->sort_direction ?: 'desc');
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
                ->with(['variants' => fn ($query) => $query->whereIn('type', ['thumbnail', 'video_poster', 'placeholder'])])
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
                ->with(['variants' => fn ($query) => $query->whereIn('type', ['thumbnail', 'video_poster', 'placeholder'])])
                ->whereNull('trashed_at')
                ->where('is_hidden', false)
                ->whereIn('status', ['ready', 'received']);

            if ($type)   $mediaQuery->where('media_type', $type);
            if ($search) $mediaQuery->where('original_filename', 'like', "%{$search}%");

            // Ruční pořadí drží `album_media.sort_order`, ne sloupec na médiu — proto se
            // musí přisadit spojením. Volba „Ručně" v nastavení alba do téhle chvíle
            // nedělala nic: uložila se a album se dál řadilo podle data.
            if ($album->sort_mode === 'manual' && ! $request->has('sort')) {
                $media = $mediaQuery
                    ->leftJoin('album_media', function ($join) use ($album) {
                        $join->on('album_media.media_item_id', '=', 'media_items.id')
                            ->where('album_media.album_id', '=', $album->id);
                    })
                    ->select('media_items.*')
                    // Nezařazené na konec, ne na začátek: fotka, kterou nikdo nepřeskládal,
                    // nemá skončit před těmi, u kterých si s pořadím někdo dal práci.
                    ->orderByRaw('CASE WHEN album_media.sort_order IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('album_media.sort_order')
                    ->orderByDesc('media_items.taken_at')
                    ->paginate(48)->withQueryString();
            } else {
                $media = $mediaQuery->orderBy($sortBy, $sortDir)->paginate(48)->withQueryString();
            }
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

    /**
     * Uloží ruční pořadí fotek v albu.
     *
     * Přijímá jen ta uuid, která se přesunula, ne celé album — přeskládat padesátou
     * fotku neznamená přepsat všech padesát řádků, a poslat celý seznam by u velkého
     * alba znamenalo posílat megabajt při každém puštění myši.
     *
     * Pořadí se čísluje po desítkách, aby šlo mezi dvě sousední vložit další bez
     * přepisování zbytku.
     */
    public function reorder(Request $request, string $uuid): \Illuminate\Http\JsonResponse
    {
        $album = Album::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $album);

        $data = $request->validate([
            'uuids' => 'required|array|max:500',
            'uuids.*' => 'required|uuid',
            // Odkud se číslování rozjíždí — u druhé a další stránky alba.
            'offset' => 'sometimes|integer|min:0',
        ]);

        $media = MediaItem::whereIn('uuid', $data['uuids'])
            ->where('gallery_space_id', $album->gallery_space_id)
            ->pluck('id', 'uuid');

        $poradi = (int) ($data['offset'] ?? 0) * 10;

        foreach ($data['uuids'] as $mediaUuid) {
            if (! isset($media[$mediaUuid])) continue;

            // updateOrInsert, protože fotka může být v albu jen přes primary_album_id a
            // řádek v album_media pak vůbec nemá — a bez něj by pořadí nebylo kam uložit.
            \Illuminate\Support\Facades\DB::table('album_media')->updateOrInsert(
                ['album_id' => $album->id, 'media_item_id' => $media[$mediaUuid]],
                ['sort_order' => $poradi, 'added_at' => now(), 'added_by' => $request->user()->id],
            );

            $poradi += 10;
        }

        // Album, které někdo právě přeskládal, chce to pořadí i ukázat.
        if ($album->sort_mode !== 'manual') {
            $album->update(['sort_mode' => 'manual', 'updated_by' => $request->user()->id]);
        }

        return response()->json(['ok' => true, 'sort_mode' => 'manual']);
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
