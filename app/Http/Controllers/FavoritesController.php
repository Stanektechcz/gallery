<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FavoritesController extends Controller
{
    public function index(Request $request): Response
    {
        $user    = $request->user();
        $space   = $user->gallerySpaces()->first();
        $members = $space->members()->get(['users.id', 'users.name']);

        $myId       = $user->id;
        $partnerIds = $members->where('id', '!=', $myId)->pluck('id');
        $allIds     = $members->pluck('id');

        // IDs favorited by me
        $myFavIds = DB::table('user_favorites')->where('user_id', $myId)->pluck('media_item_id');

        // IDs favorited by ALL members (shared)
        $sharedIds = DB::table('user_favorites')
            ->whereIn('user_id', $allIds)
            ->select('media_item_id')
            ->groupBy('media_item_id')
            ->havingRaw('COUNT(DISTINCT user_id) >= ?', [$allIds->count()])
            ->pluck('media_item_id');

        // IDs favorited by partner but NOT by me
        $partnerOnlyIds = DB::table('user_favorites')
            ->whereIn('user_id', $partnerIds)
            ->whereNotIn('media_item_id', $myFavIds)
            ->pluck('media_item_id');

        $baseQuery = fn($ids) => MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('status', 'ready')
            ->whereIn('id', $ids)
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('taken_at')
            ->limit(200)
            ->get()
            ->map(fn($m) => $this->formatItem($m));

        return Inertia::render('Favorites/Index', [
            'my_items'      => $baseQuery($myFavIds),
            'shared_items'  => $baseQuery($sharedIds),
            'partner_items' => $partnerIds->isEmpty() ? [] : $baseQuery($partnerOnlyIds),
            'members'       => $members->map(fn($m) => [
                'id'    => $m->id,
                'name'  => $m->name,
                'is_me' => $m->id === $myId,
            ])->values(),
        ]);
    }

    public function toggle(Request $request, string $uuid): \Illuminate\Http\JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $media = MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->firstOrFail();

        $exists = DB::table('user_favorites')
            ->where('user_id', $user->id)
            ->where('media_item_id', $media->id)
            ->exists();

        if ($exists) {
            DB::table('user_favorites')
                ->where('user_id', $user->id)
                ->where('media_item_id', $media->id)
                ->delete();
            $isMine = false;
        } else {
            DB::table('user_favorites')->insert([
                'user_id'       => $user->id,
                'media_item_id' => $media->id,
                'created_at'    => now(),
            ]);
            $isMine = true;
        }

        // Aggregate: any user favorited?
        $anyFav = DB::table('user_favorites')
            ->where('media_item_id', $media->id)
            ->exists();

        $media->update(['is_favorite' => $anyFav]);

        // Is shared (all space members favorited)?
        $memberIds = $space->members()->pluck('users.id');
        $favCount  = DB::table('user_favorites')
            ->where('media_item_id', $media->id)
            ->whereIn('user_id', $memberIds)
            ->count();
        $isShared = $memberIds->count() > 1 && $favCount >= $memberIds->count();

        // Partner name for the UI badge
        $partner = $space->members()
            ->where('users.id', '!=', $user->id)
            ->first(['users.name']);

        return response()->json([
            'is_my_favorite'     => $isMine,
            'is_shared_favorite' => $isShared,
            'is_favorite'        => $anyFav,   // backward-compat
            'partner_name'       => $partner?->name,
        ]);
    }

    private function formatItem(MediaItem $m): array
    {
        return [
            'id'            => $m->id,
            'uuid'          => $m->uuid,
            'media_type'    => $m->media_type,
            'taken_at'      => $m->taken_at?->toIso8601String(),
            'width'         => $m->width,
            'height'        => $m->height,
            'is_favorite'   => $m->is_favorite,
            'rating'        => $m->rating,
            'display_title' => $m->display_title ?? $m->original_filename,
            'variants'      => $m->variants->map(fn($v) => [
                'type'           => $v->type,
                'url'            => asset('storage/' . $v->path),
                'dominant_color' => $v->dominant_color,
                'aspect_ratio'   => $v->aspect_ratio,
            ]),
        ];
    }
}

