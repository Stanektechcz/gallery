<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $tags  = Tag::where('gallery_space_id', $space->id)->orderBy('name')->get();
        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $data  = $request->validate(['name' => 'required|string|max:100', 'parent_id' => 'nullable|integer|exists:tags,id', 'color' => 'nullable|string|max:20']);
        $space = $request->user()->gallerySpaces()->first();
        $tag   = Tag::create(array_merge($data, ['gallery_space_id' => $space->id, 'slug' => Str::slug($data['name']), 'created_by' => $request->user()->id]));
        return response()->json($tag, 201);
    }

    public function show(Tag $tag): JsonResponse
    {
        return response()->json($tag);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $data = $request->validate(['name' => 'sometimes|string|max:100', 'color' => 'nullable|string|max:20', 'parent_id' => 'nullable|integer|exists:tags,id']);
        if (isset($data['name'])) $data['slug'] = Str::slug($data['name']);
        $tag->update($data);
        return response()->json($tag);
    }

    public function destroy(int $id): JsonResponse
    {
        Tag::findOrFail($id)->delete();
        return response()->json(['status' => 'deleted']);
    }
}
