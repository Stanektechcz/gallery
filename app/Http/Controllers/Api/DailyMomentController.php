<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Moments\DailyMomentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyMomentController extends Controller
{
    public function __construct(private readonly DailyMomentService $moments) {}

    /** Today's prompt and whatever this person is allowed to see of it. */
    public function show(Request $request): JsonResponse
    {
        $space = $this->space($request);

        return response()->json($this->moments->state($space, $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);

        $data = $request->validate([
            'back_uuid' => 'nullable|uuid',
            'front_uuid' => 'nullable|uuid',
            'caption' => 'nullable|string|max:500',
        ]);

        $space = $this->space($request);
        $this->moments->post($space, $request->user(), $data);

        // The whole state is returned rather than the new entry alone: posting is what
        // unlocks the other person's picture, so this is the moment the page needs it.
        return response()->json($this->moments->state($space, $request->user()));
    }

    /** Days this person answered, newest first. */
    public function history(Request $request): JsonResponse
    {
        $space = $this->space($request);

        return response()->json([
            'moments' => $this->moments->history($space, $request->user(), min(120, max(1, $request->integer('limit', 60)))),
        ]);
    }

    private function space(Request $request): GallerySpace
    {
        $query = $request->user()->gallerySpaces();
        $id = $request->integer('gallery_space_id');

        return $id ? $query->whereKey($id)->firstOrFail() : $query->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze moment uložit.');
    }
}
