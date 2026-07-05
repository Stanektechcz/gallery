<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReactionController extends Controller
{
    private const ALLOWED = ['love', 'funny', 'memory', 'top'];

    public function index(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        $user  = $request->user();

        $counts = DB::table('media_reactions')
            ->where('media_item_id', $media->id)
            ->selectRaw('reaction, COUNT(*) as count')
            ->groupBy('reaction')
            ->pluck('count', 'reaction')
            ->toArray();

        $mine = DB::table('media_reactions')
            ->where('media_item_id', $media->id)
            ->where('user_id', $user->id)
            ->value('reaction');

        // Who reacted with what (for pair gallery display)
        $memberIds = $user->gallerySpaces()->first()->members()->pluck('users.id');
        $details   = DB::table('media_reactions')
            ->join('users', 'users.id', '=', 'media_reactions.user_id')
            ->where('media_reactions.media_item_id', $media->id)
            ->whereIn('media_reactions.user_id', $memberIds)
            ->get(['users.id as user_id', 'users.name', 'media_reactions.reaction'])
            ->map(fn($r) => [
                'user_id'  => $r->user_id,
                'name'     => $r->name,
                'initial'  => mb_strtoupper(mb_substr($r->name, 0, 1)),
                'reaction' => $r->reaction,
                'is_me'    => $r->user_id === $user->id,
            ]);

        return response()->json(['counts' => $counts, 'mine' => $mine, 'details' => $details]);
    }

    public function react(Request $request, string $uuid): JsonResponse
    {
        $media    = MediaItem::where('uuid', $uuid)->firstOrFail();
        $user     = $request->user();
        $reaction = $request->input('reaction');

        if ($reaction && !in_array($reaction, self::ALLOWED)) {
            return response()->json(['error' => 'Invalid reaction'], 422);
        }

        // Remove existing
        DB::table('media_reactions')
            ->where('media_item_id', $media->id)
            ->where('user_id', $user->id)
            ->delete();

        // Add new if set
        if ($reaction) {
            DB::table('media_reactions')->insert([
                'media_item_id' => $media->id,
                'user_id'       => $user->id,
                'reaction'      => $reaction,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $counts = DB::table('media_reactions')
            ->where('media_item_id', $media->id)
            ->selectRaw('reaction, COUNT(*) as count')
            ->groupBy('reaction')
            ->pluck('count', 'reaction')
            ->toArray();

        return response()->json(['counts' => $counts, 'mine' => $reaction]);
    }
}
