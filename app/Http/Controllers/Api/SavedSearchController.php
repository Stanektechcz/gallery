<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\SavedSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $space = $this->requireSpace($request);
        $searches = SavedSearch::where('gallery_space_id', $space->id)
            ->where(fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->orWhere('is_shared', true))
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_used_at')
            ->orderBy('name')
            ->get();

        return response()->json($searches);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $space = $this->requireSpace($request);
        $search = SavedSearch::create(array_merge($data, ['user_id' => $request->user()->id, 'gallery_space_id' => $space->id]));

        return response()->json($search, 201);
    }

    public function show(Request $request, SavedSearch $savedSearch): JsonResponse
    {
        $this->authorizeView($request, $savedSearch);
        if ($savedSearch->user_id === $request->user()->id) {
            $savedSearch->update(['last_used_at' => now()]);
        }

        return response()->json($savedSearch);
    }

    public function update(Request $request, SavedSearch $savedSearch): JsonResponse
    {
        $this->authorizeOwner($request, $savedSearch);
        $savedSearch->update($request->validate($this->rules(true)));

        return response()->json($savedSearch);
    }

    public function destroy(Request $request, SavedSearch $savedSearch): JsonResponse
    {
        $this->authorizeOwner($request, $savedSearch);
        $savedSearch->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => "{$required}|string|max:100",
            // Bez omezení počtu položek by šlo uložit libovolně velký JSON.
            'filters_json' => "{$required}|array|max:50",
            'view_type' => 'sometimes|in:grid,timeline,map,calendar,table',
            'layout_config' => 'nullable|array|max:50',
            'sort_by' => 'sometimes|in:taken_at,uploaded_at,rating,size_bytes,original_filename',
            'sort_direction' => 'sometimes|in:asc,desc',
            'icon' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:20',
            'is_shared' => 'sometimes|boolean',
            'is_pinned' => 'sometimes|boolean',
        ];
    }

    private function authorizeView(Request $request, SavedSearch $savedSearch): void
    {
        $spaceId = $request->user()->gallerySpaces()->first()?->id;
        abort_unless(
            $savedSearch->gallery_space_id === $spaceId
            && ($savedSearch->user_id === $request->user()->id || $savedSearch->is_shared),
            404
        );
    }

    private function authorizeOwner(Request $request, SavedSearch $savedSearch): void
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);
    }

    /** Prostor přihlášeného, nebo čistá 403 — účet bez prostoru nemá co ukládat. */
    private function requireSpace(Request $request): GallerySpace
    {
        $space = $request->user()->gallerySpaces()->first();
        abort_if($space === null, 403, 'Nemáte přiřazený prostor galerie.');

        return $space;
    }
}
