<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Geo\PlaceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoController extends Controller
{
    public function __construct(private readonly PlaceSearchService $places) {}

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:200',
            'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
        ]);

        $space = $request->user()->gallerySpaces()->first();

        return response()->json([
            'results' => $this->places->suggest($data['q'], $space, [
                'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
                'lon' => isset($data['lon']) ? (float) $data['lon'] : null,
            ]),
        ]);
    }

    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
        ]);

        return response()->json([
            'result' => $this->places->reverse((float) $data['lat'], (float) $data['lon']),
        ]);
    }
}
