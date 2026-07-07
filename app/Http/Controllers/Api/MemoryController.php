<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemoryInteraction;
use App\Models\MemoryPreference;
use App\Services\Media\MemoryDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    public function index(Request $request, MemoryDiscoveryService $memories): JsonResponse
    {
        return response()->json($memories->discover($request->user()));
    }

    public function interact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fingerprint' => 'required|string|size:64',
            'memory_type' => 'required|in:' . implode(',', MemoryDiscoveryService::TYPES),
            'action' => 'required|in:saved,dismissed,snoozed',
            'metadata' => 'nullable|array',
        ]);

        $interaction = MemoryInteraction::updateOrCreate(
            ['user_id' => $request->user()->id, 'fingerprint' => $data['fingerprint']],
            [
                'memory_type' => $data['memory_type'],
                'action' => $data['action'],
                'snoozed_until' => $data['action'] === 'snoozed' ? now()->addDays(30) : null,
                'metadata' => $data['metadata'] ?? null,
            ]
        );

        return response()->json($interaction);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(MemoryPreference::firstOrCreate(['user_id' => $request->user()->id]));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'frequency' => 'sometimes|in:more,normal,less,off',
            'enabled_types' => 'nullable|array',
            'enabled_types.*' => 'in:' . implode(',', MemoryDiscoveryService::TYPES),
            'hidden_person_ids' => 'nullable|array',
            'hidden_person_ids.*' => 'integer',
            'hidden_place_ids' => 'nullable|array',
            'hidden_place_ids.*' => 'integer',
            'hidden_date_ranges' => 'nullable|array',
            'include_archived' => 'sometimes|boolean',
        ]);
        $preferences = MemoryPreference::updateOrCreate(['user_id' => $request->user()->id], $data);

        return response()->json($preferences);
    }
}

