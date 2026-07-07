<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\SharedLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $spaceId = $user->gallerySpaces()->value('gallery_spaces.id');
        $shareIds = SharedLink::where('created_by', $user->id)->pluck('id');
        return Inertia::render('Privacy/Index', ['overview' => [
            'active_shares' => SharedLink::where('created_by', $user->id)->where('is_active', true)->count(),
            'password_shares' => SharedLink::where('created_by', $user->id)->whereNotNull('password_hash')->count(),
            'pending_uploads' => DB::table('guest_uploads')->whereIn('shared_link_id', $shareIds)->where('status', 'pending')->count(),
            'hidden_media' => MediaItem::where('gallery_space_id', $spaceId)->where('is_hidden', true)->count(),
            'views_30_days' => DB::table('share_access_logs')->whereIn('shared_link_id', $shareIds)->where('created_at', '>=', now()->subDays(30))->where('action', 'view')->count(),
        ], 'legacy' => DB::table('legacy_plans')->where('user_id', $user->id)->first()]);
    }

    public function updateLegacy(Request $request): JsonResponse
    {
        $data = $request->validate(['contact_name' => 'nullable|string|max:255', 'contact_email' => 'nullable|email|max:255', 'status' => 'required|in:disabled,draft,ready', 'inactivity_months' => 'required|integer|min:3|max:60', 'scope' => 'nullable|array']);
        if ($data['status'] === 'ready') abort_unless(filled($data['contact_name'] ?? null) && filled($data['contact_email'] ?? null), 422, 'Pro aktivaci doplňte kontaktní osobu a e-mail.');
        DB::table('legacy_plans')->updateOrInsert(['user_id' => $request->user()->id], array_merge($data, ['scope' => json_encode($data['scope'] ?? ['albums', 'media']), 'verified_at' => $data['status'] === 'ready' ? now() : null, 'created_at' => now(), 'updated_at' => now()]));
        return response()->json(DB::table('legacy_plans')->where('user_id', $request->user()->id)->first());
    }
}
