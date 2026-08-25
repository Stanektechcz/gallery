<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    public function index(Request $request, string $uuid): JsonResponse
    {
        $media    = MediaItem::where('uuid', $uuid)->firstOrFail();
        $user     = $request->user();

        $comments = DB::table('media_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.media_item_id', $media->id)
            ->where(fn($q) => $q->where('c.is_private', false)->orWhere('c.user_id', $user->id))
            ->select(['c.id', 'c.body', 'c.is_private', 'c.created_at', 'u.name as user_name', 'u.id as user_id'])
            ->orderBy('c.created_at')
            ->get()
            ->map(fn ($comment) => (array) $comment + ['is_mine' => $comment->user_id === $user->id]);

        return response()->json($comments);
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        $user  = $request->user();

        $validated = $request->validate([
            'body'       => 'required|string|max:2000',
            'is_private' => 'boolean',
        ]);

        $id = DB::table('media_comments')->insertGetId([
            'media_item_id' => $media->id,
            'user_id'       => $user->id,
            'body'          => $validated['body'],
            'is_private'    => $validated['is_private'] ?? false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $comment = DB::table('media_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->select(['c.id', 'c.body', 'c.is_private', 'c.created_at', 'u.name as user_name', 'u.id as user_id'])
            ->first();

        return response()->json((array) $comment + ['is_mine' => true], 201);
    }

    public function destroy(Request $request, string $uuid, int $id): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();

        // Cizí komentář se nemaže. Dřív se v takovém případě vracelo „deleted“ a volající
        // se dozvěděl úspěch, který se nestal.
        $deleted = DB::table('media_comments')
            ->where('id', $id)
            ->where('media_item_id', $media->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        abort_if($deleted === 0, 404, 'Komentář neexistuje nebo není váš.');

        return response()->json(['status' => 'deleted']);
    }
}
