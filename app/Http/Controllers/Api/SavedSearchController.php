<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $searches = SavedSearch::where('user_id', $request->user()->id)
            ->orWhere(fn($q) => $q->where('gallery_space_id', $space->id)->where('is_shared', true))
            ->orderBy('name')
            ->get();
        return response()->json($searches);
    }

    public function store(Request $request): JsonResponse
    {
        $data  = $request->validate(['name' => 'required|string|max:100', 'filters_json' => 'required|array', 'is_shared' => 'boolean']);
        $space = $request->user()->gallerySpaces()->first();
        $search = SavedSearch::create(array_merge($data, ['user_id' => $request->user()->id, 'gallery_space_id' => $space->id]));
        return response()->json($search, 201);
    }

    public function show(SavedSearch $savedSearch): JsonResponse
    {
        return response()->json($savedSearch);
    }
    public function update(Request $request, SavedSearch $savedSearch): JsonResponse
    {
        $savedSearch->update($request->validate(['name' => 'sometimes|string', 'is_shared' => 'boolean']));
        return response()->json($savedSearch);
    }
    public function destroy(SavedSearch $savedSearch): JsonResponse
    {
        $savedSearch->delete();
        return response()->json(['status' => 'deleted']);
    }
}
