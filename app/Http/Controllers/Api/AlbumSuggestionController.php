<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Media\UnassignedAlbumSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumSuggestionController extends Controller
{
    public function __construct(private readonly UnassignedAlbumSuggestionService $suggestions) {}

    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request, $request->integer('gallery_space_id'));
        return response()->json(['available' => $this->suggestions->available(), 'suggestions' => $this->suggestions->suggestions($space, $request->user())]);
    }

    public function accept(Request $request, string $fingerprint): JsonResponse
    {
        $this->write($request);
        $data = $request->validate([
            'gallery_space_id' => 'required|integer', 'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000', 'media_uuids' => 'required|array|min:1|max:200',
            'media_uuids.*' => 'required|uuid', 'cover_media_uuid' => 'nullable|uuid', 'create_memory' => 'sometimes|boolean',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        if ($decision = $this->suggestions->decision($space, $fingerprint)) {
            abort_unless($decision->action === 'accepted' && $decision->album, 409, 'Tento návrh už byl odmítnut.');
            return response()->json(['album' => ['uuid' => $decision->album->uuid, 'title' => $decision->album->title,
                'media_count' => (int) $decision->album->media_count], 'created' => false, 'already_decided' => true]);
        }
        $suggestion = $this->suggestions->find($space, $request->user(), $fingerprint);
        abort_unless($suggestion, 404, 'Návrh už není aktuální. Obnovte seznam návrhů.');
        $result = $this->suggestions->accept($space, $request->user(), $suggestion, $data);
        return response()->json($result, $result['created'] ? 201 : 200);
    }

    public function dismiss(Request $request, string $fingerprint): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['gallery_space_id' => 'required|integer']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        abort_if($this->suggestions->decision($space, $fingerprint), 409, 'O tomto návrhu už bylo rozhodnuto.');
        $suggestion = $this->suggestions->find($space, $request->user(), $fingerprint);
        abort_unless($suggestion, 404, 'Návrh už není aktuální.');
        $this->suggestions->dismiss($space, $request->user(), $suggestion);
        return response()->json(['dismissed' => true]);
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = $request->user()->gallerySpaces();
        return $id ? $query->whereKey($id)->firstOrFail() : $query->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze návrhy alb měnit.');
    }
}
