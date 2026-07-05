<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JourneyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $events = DB::table('journey_events')
            ->where('gallery_space_id', $space->id)
            ->orderByDesc('event_date')
            ->get();

        return response()->json($events);
    }

    public function store(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'story'       => 'nullable|string|max:5000',
            'event_date'  => 'required|date',
            'place_name'  => 'nullable|string|max:255',
            'emotion'     => 'nullable|string|max:10',
            'song_link'   => 'nullable|url|max:512',
        ]);

        $id = DB::table('journey_events')->insertGetId([
            'gallery_space_id' => $space->id,
            'created_by'       => $user->id,
            'title'            => $validated['title'],
            'story'            => $validated['story'] ?? null,
            'event_date'       => $validated['event_date'],
            'place_name'       => $validated['place_name'] ?? null,
            'emotion'          => $validated['emotion'] ?? '❤️',
            'song_link'        => $validated['song_link'] ?? null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json(DB::table('journey_events')->find($id), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        DB::table('journey_events')
            ->where('id', $id)
            ->where('gallery_space_id', $space->id)
            ->delete();

        return response()->json(['status' => 'deleted']);
    }
}
