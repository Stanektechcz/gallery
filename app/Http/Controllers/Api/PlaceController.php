<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Place::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data  = $request->validate(['name' => 'required|string|max:200', 'country' => 'nullable|string', 'city' => 'nullable|string', 'latitude' => 'nullable|numeric', 'longitude' => 'nullable|numeric']);
        $place = Place::create(array_merge($data, ['source' => 'manual', 'created_by' => $request->user()->id]));
        return response()->json($place, 201);
    }

    public function show(Place $place): JsonResponse
    {
        return response()->json($place);
    }

    public function update(Request $request, Place $place): JsonResponse
    {
        $place->update($request->validate(['name' => 'sometimes|string|max:200', 'country' => 'nullable|string', 'city' => 'nullable|string', 'latitude' => 'nullable|numeric', 'longitude' => 'nullable|numeric']));
        return response()->json($place);
    }

    public function destroy(Place $place): JsonResponse
    {
        $place->delete();
        return response()->json(['status' => 'deleted']);
    }
}
