<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevisitSuggestionController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $spaceIds = $request->user()->gallerySpaces()->pluck('gallery_spaces.id');
        $source = MediaItem::where('uuid', $uuid)->whereIn('gallery_space_id', $spaceIds)->whereNull('trashed_at')->firstOrFail();
        if ($source->latitude === null || $source->longitude === null) return response()->json(['source' => $source->uuid, 'candidates' => [], 'message' => 'Zdrojová fotografie nemá GPS souřadnice.']);
        $candidates = MediaItem::where('gallery_space_id', $source->gallery_space_id)->whereNull('trashed_at')->where('id', '!=', $source->id)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereRaw('ABS(latitude - ?) < 0.015 AND ABS(longitude - ?) < 0.015', [$source->latitude, $source->longitude])
            ->orderByDesc('taken_at')->limit(24)->get(['uuid', 'display_title', 'original_filename', 'taken_at', 'latitude', 'longitude']);
        return response()->json(['source' => $source->uuid, 'candidates' => $candidates, 'prompt' => $source->taken_at ? 'Zopakujte snímek ve stejném místě a porovnejte jej v Porovnání.' : null]);
    }
}
