<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * GET /api/v1/search
     * Full-text + structured filter search — no AI.
     */
    public function search(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $query = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('status', 'ready');

        // Full-text search
        $q = $request->input('q');
        if ($q && strlen(trim($q)) >= 2) {
            $query->whereFullText('search_text', $q, ['mode' => 'boolean'])
                  ->orWhere('original_filename', 'like', "%{$q}%");
        }

        // Structured filters
        if ($mediaType = $request->input('media_type')) {
            $query->where('media_type', $mediaType);
        }
        if ($dateFrom = $request->input('date_from')) {
            $query->where('taken_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->where('taken_at', '<=', $dateTo);
        }
        if ($request->boolean('has_gps')) {
            $query->whereNotNull('latitude')->whereNotNull('longitude');
        }
        if ($request->boolean('no_gps')) {
            $query->whereNull('latitude');
        }
        if ($rating = $request->input('min_rating')) {
            $query->where('rating', '>=', (int) $rating);
        }
        if ($request->boolean('favorites_only')) {
            $query->where('is_favorite', true);
        }
        if ($request->boolean('archived')) {
            $query->where('is_archived', true);
        } else {
            $query->where('is_archived', false);
        }
        if ($extension = $request->input('extension')) {
            $query->where('extension', strtolower($extension));
        }
        if ($camera = $request->input('camera')) {
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
        if ($orientation = $request->input('orientation')) {
            if ($orientation === 'landscape') {
                $query->whereColumn('width', '>', 'height');
            } elseif ($orientation === 'portrait') {
                $query->whereColumn('height', '>', 'width');
            }
        }

        $perPage   = min((int) $request->input('per_page', 40), 100);
        $paginated = $query->with(['variants' => fn($q) => $q->where('type', 'thumbnail')])
            ->orderBy('taken_at', 'desc')
            ->paginate($perPage);

        return response()->json($paginated);
    }
}
