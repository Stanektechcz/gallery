<?php

namespace App\Http\Controllers\Api;

use App\Models\Album;
use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Place;
use App\Models\SavedSearch;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    /**
     * GET /api/v1/search
     * Full-text + structured filter search — no AI.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:200',
            'media_type' => 'nullable|in:photo,video',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'has_gps' => 'nullable|boolean',
            'no_gps' => 'nullable|boolean',
            'min_rating' => 'nullable|integer|min:1|max:5',
            'favorites_only' => 'nullable|boolean',
            'archived' => 'nullable|boolean',
            'extension' => 'nullable|string|max:20',
            'camera' => 'nullable|string|max:100',
            'orientation' => 'nullable|in:landscape,portrait,square',
            'sort_by' => 'nullable|in:taken_at,uploaded_at,rating,size_bytes,original_filename',
            'sort_direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $interpreted = $this->interpretQuery(trim((string) ($validated['q'] ?? '')));
        $filters = array_merge($interpreted['filters'], array_filter($validated, fn ($value) => $value !== null && $value !== ''));

        $query = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->where('status', 'ready');

        // Full-text search
        $q = $interpreted['text'];
        if ($q && strlen(trim($q)) >= 2) {
            $query->where(function ($textQuery) use ($q) {
                if (DB::connection()->getDriverName() === 'mysql') {
                    $textQuery->whereFullText('search_text', $q, ['mode' => 'boolean']);
                } else {
                    $textQuery->where('search_text', 'like', "%{$q}%");
                }
                $textQuery->orWhere('original_filename', 'like', "%{$q}%")
                    ->orWhere('display_title', 'like', "%{$q}%")
                    ->orWhere('caption', 'like', "%{$q}%");
            });
        }

        // Structured filters
        if ($mediaType = ($filters['media_type'] ?? null)) {
            $query->where('media_type', $mediaType);
        }
        if ($dateFrom = ($filters['date_from'] ?? null)) {
            $query->where('taken_at', '>=', $dateFrom);
        }
        if ($dateTo = ($filters['date_to'] ?? null)) {
            $query->where('taken_at', '<=', $dateTo);
        }
        if ($filters['has_gps'] ?? false) {
            $query->whereNotNull('latitude')->whereNotNull('longitude');
        }
        if ($filters['no_gps'] ?? false) {
            $query->whereNull('latitude');
        }
        if ($rating = ($filters['min_rating'] ?? null)) {
            $query->where('rating', '>=', (int) $rating);
        }
        if ($filters['favorites_only'] ?? false) {
            $query->where('is_favorite', true);
        }
        if ($filters['archived'] ?? false) {
            $query->where('is_archived', true);
        } else {
            $query->where('is_archived', false);
        }
        if ($extension = ($filters['extension'] ?? null)) {
            $query->where('extension', strtolower($extension));
        }
        if ($camera = ($filters['camera'] ?? null)) {
            $query->where(function ($q) use ($camera) {
                $q->where('camera_make', 'like', "%{$camera}%")
                  ->orWhere('camera_model', 'like', "%{$camera}%");
            });
        }
        if ($albumId = $request->input('album_id')) {
            // Support album subtree search
            $albumIds = \DB::table('album_closure')
                ->where('ancestor_id', $albumId)
                ->pluck('descendant_id');
            $query->whereHas('albums', fn($q) => $q->whereIn('albums.id', $albumIds));
        }
        if ($tagIds = $request->input('tag_ids')) {
            $tagIdArray = is_array($tagIds) ? $tagIds : explode(',', $tagIds);
            $query->whereHas('tags', fn($q) => $q->whereIn('tags.id', $tagIdArray));
        }
        if ($personIds = $request->input('person_ids')) {
            $personIdArray = is_array($personIds) ? $personIds : explode(',', $personIds);
            $query->whereHas('people', fn($q) => $q->whereIn('people.id', $personIdArray));
        }
        if ($minSize = $request->input('min_size')) {
            $query->where('size_bytes', '>=', (int) $minSize);
        }
        if ($maxSize = $request->input('max_size')) {
            $query->where('size_bytes', '<=', (int) $maxSize);
        }
        if ($minDuration = $request->input('min_duration')) {
            $query->where('duration_ms', '>=', (int) $minDuration * 1000);
        }
        if ($orientation = ($filters['orientation'] ?? null)) {
            if ($orientation === 'landscape') {
                $query->whereColumn('width', '>', 'height');
            } elseif ($orientation === 'portrait') {
                $query->whereColumn('height', '>', 'width');
            } elseif ($orientation === 'square') {
                $query->whereRaw('width BETWEEN height * 0.95 AND height * 1.05');
            }
        }

        $facets = [
            'photos' => (clone $query)->where('media_type', 'photo')->count(),
            'videos' => (clone $query)->where('media_type', 'video')->count(),
            'favorites' => (clone $query)->where('is_favorite', true)->count(),
            'with_gps' => (clone $query)->whereNotNull('latitude')->whereNotNull('longitude')->count(),
        ];

        $perPage   = min((int) ($filters['per_page'] ?? 40), 100);
        $sortBy = $filters['sort_by'] ?? 'taken_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $paginated = $query->with(['variants' => fn($q) => $q->where('type', 'thumbnail')])
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'facets' => $facets,
            'interpreted' => [
                'query' => $q,
                'filters' => $interpreted['filters'],
                'labels' => $interpreted['labels'],
            ],
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'required|string|min:1|max:100']);
        $space = $request->user()->gallerySpaces()->first();
        $term = trim($data['q']);
        $like = "%{$term}%";
        $limit = 4;

        $results = collect()
            ->concat(Album::where('gallery_space_id', $space->id)->where('title', 'like', $like)->limit($limit)->get()->map(fn ($item) => [
                'type' => 'album', 'id' => $item->id, 'label' => $item->title, 'url' => "/albums/{$item->uuid}", 'icon' => '📁',
            ]))
            ->concat(Person::where('gallery_space_id', $space->id)->where('name', 'like', $like)->limit($limit)->get()->map(fn ($item) => [
                'type' => 'person', 'id' => $item->id, 'label' => $item->name, 'filters' => ['person_ids' => [$item->id]], 'icon' => '👤',
            ]))
            ->concat(Place::where('gallery_space_id', $space->id)->where('name', 'like', $like)->limit($limit)->get()->map(fn ($item) => [
                'type' => 'place', 'id' => $item->id, 'label' => $item->name, 'url' => "/places/{$item->id}", 'icon' => '📍',
            ]))
            ->concat(Tag::where('gallery_space_id', $space->id)->where('name', 'like', $like)->limit($limit)->get()->map(fn ($item) => [
                'type' => 'tag', 'id' => $item->id, 'label' => $item->name, 'filters' => ['tag_ids' => [$item->id]], 'icon' => '🏷️',
            ]))
            ->concat(DB::table('trips')->where('gallery_space_id', $space->id)->where('name', 'like', $like)->limit($limit)->get()->map(fn ($item) => [
                'type' => 'trip', 'id' => $item->id, 'label' => $item->name, 'url' => "/trips?trip={$item->id}", 'icon' => '🗺️',
            ]))
            ->concat(SavedSearch::where('gallery_space_id', $space->id)
                ->where(fn ($query) => $query->where('user_id', $request->user()->id)->orWhere('is_shared', true))
                ->where('name', 'like', $like)->limit($limit)->get()->map(fn ($item) => [
                    'type' => 'view', 'id' => $item->id, 'label' => $item->name, 'filters' => $item->filters_json, 'icon' => $item->icon ?: '✨',
                ]))
            ->take(20)
            ->values();

        return response()->json($results);
    }

    private function interpretQuery(string $query): array
    {
        $text = mb_strtolower($query);
        $filters = [];
        $labels = [];

        $patterns = [
            '/\b(videa?|video)\b/u' => ['media_type', 'video', 'Videa'],
            '/\b(fotky|fotografie|foto)\b/u' => ['media_type', 'photo', 'Fotografie'],
            '/\b(oblíbené|oblibene|favority)\b/u' => ['favorites_only', true, 'Oblíbené'],
            '/\b(s gps|na mapě|na mape)\b/u' => ['has_gps', true, 'S GPS'],
            '/\b(bez gps|bez polohy)\b/u' => ['no_gps', true, 'Bez GPS'],
        ];
        foreach ($patterns as $pattern => [$key, $value, $label]) {
            if (preg_match($pattern, $text)) {
                $filters[$key] = $value;
                $labels[] = $label;
                $text = preg_replace($pattern, ' ', $text) ?? $text;
            }
        }

        if (preg_match('/\b(léto|leto)\s+(20\d{2})\b/u', $text, $match)) {
            $filters['date_from'] = "{$match[2]}-06-01";
            $filters['date_to'] = "{$match[2]}-08-31 23:59:59";
            $labels[] = "Léto {$match[2]}";
            $text = str_replace($match[0], ' ', $text);
        } elseif (preg_match('/\b(20\d{2})\b/', $text, $match)) {
            $filters['date_from'] = "{$match[1]}-01-01";
            $filters['date_to'] = "{$match[1]}-12-31 23:59:59";
            $labels[] = $match[1];
            $text = str_replace($match[0], ' ', $text);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return ['text' => $text, 'filters' => $filters, 'labels' => $labels];
    }
}
