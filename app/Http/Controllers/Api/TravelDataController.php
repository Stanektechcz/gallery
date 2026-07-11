<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\FreeTravelDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TravelDataController extends Controller
{
    public function weather(Request $request, FreeTravelDataService $service): JsonResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'date' => 'nullable|date']);
        return response()->json($service->weather((float) $data['latitude'], (float) $data['longitude'], $data['date'] ?? null));
    }

    public function exchangeRate(Request $request, FreeTravelDataService $service): JsonResponse
    {
        $data = $request->validate(['base' => 'required|string|size:3', 'quote' => 'required|string|size:3|different:base', 'date' => 'nullable|date']);
        return response()->json($service->rate(strtoupper($data['base']), strtoupper($data['quote']), $data['date'] ?? null));
    }

    public function route(Request $request, FreeTravelDataService $service): JsonResponse
    {
        $data = $request->validate(['from_latitude' => 'required|numeric|between:-90,90', 'from_longitude' => 'required|numeric|between:-180,180', 'to_latitude' => 'required|numeric|between:-90,90', 'to_longitude' => 'required|numeric|between:-180,180', 'profile' => 'nullable|in:driving-car,cycling-regular,foot-walking']);
        return response()->json($service->route((float) $data['from_latitude'], (float) $data['from_longitude'], (float) $data['to_latitude'], (float) $data['to_longitude'], $data['profile'] ?? 'driving-car'));
    }
}
