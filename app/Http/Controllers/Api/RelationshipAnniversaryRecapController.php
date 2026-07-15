<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Memories\RelationshipAnniversaryRecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationshipAnniversaryRecapController extends Controller
{
    public function __construct(private readonly RelationshipAnniversaryRecapService $recaps) {}

    public function show(Request $request): JsonResponse
    {
        $space = $this->space($request, $request->integer('gallery_space_id'));
        return response()->json($this->recaps->overview($space));
    }

    public function store(Request $request): JsonResponse
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze vytvářet výroční album.');
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:5000',
            'media_uuids' => 'required|array|min:1|max:200',
            'media_uuids.*' => 'required|uuid|distinct',
            'cover_media_uuid' => 'nullable|uuid',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $result = $this->recaps->save($space, $request->user(), $data);
        return response()->json($result, $result['created'] ? 201 : 200);
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = $request->user()->gallerySpaces();
        return $id ? $query->whereKey($id)->firstOrFail() : $query->firstOrFail();
    }
}
