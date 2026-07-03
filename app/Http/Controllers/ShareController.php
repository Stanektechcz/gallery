<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\SharedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ShareController extends Controller
{
    public function index(Request $request): Response
    {
        $shares = SharedLink::where('created_by', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Shares/Index', ['shares' => $shares]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'target_type'       => 'required|in:album,media,selection',
            'target_id'         => 'nullable|integer',
            'name'              => 'nullable|string|max:200',
            'password'          => 'nullable|string|min:4',
            'expires_at'        => 'nullable|date|after:now',
            'allow_download'    => 'boolean',
            'allow_guest_upload' => 'boolean',
            'hide_gps'          => 'boolean',
            'max_uses'          => 'nullable|integer|min:1',
            'media_ids'         => 'nullable|array',
            'media_ids.*'       => 'integer|exists:media_items,id',
        ]);

        $link = SharedLink::create([
            'created_by'        => $request->user()->id,
            'gallery_space_id'  => $request->user()->gallerySpaces()->first()->id,
            'target_type'       => $data['target_type'],
            'target_id'         => $data['target_id'] ?? null,
            'name'              => $data['name'] ?? null,
            'password_hash'     => isset($data['password']) ? Hash::make($data['password']) : null,
            'expires_at'        => $data['expires_at'] ?? null,
            'allow_download'    => $data['allow_download'] ?? true,
            'allow_guest_upload' => $data['allow_guest_upload'] ?? false,
            'hide_gps'          => $data['hide_gps'] ?? false,
            'max_uses'          => $data['max_uses'] ?? null,
        ]);

        if (!empty($data['media_ids'])) {
            $link->mediaItems()->attach($data['media_ids']);
        }

        AuditLog::record('share.create', $link, ['token' => $link->token, 'target_type' => $link->target_type]);

        return response()->json([
            'token' => $link->token,
            'url'   => route('share.show', $link->token),
        ]);
    }

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();

        if (!$link->isAccessible()) {
            return Inertia::render('Shares/Expired');
        }

        if ($link->password_hash && !session("share_verified_{$token}")) {
            return Inertia::render('Shares/PasswordGate', ['token' => $token]);
        }

        // Increment use count
        $link->increment('use_count');

        $media = match ($link->target_type) {
            'album'     => MediaItem::where('primary_album_id', $link->target_id)->with('variants')->limit(100)->get(),
            'media'     => MediaItem::where('id', $link->target_id)->with('variants')->get(),
            'selection' => $link->mediaItems()->with('variants')->get(),
            default     => collect(),
        };

        // Strip GPS if configured
        if ($link->hide_gps) {
            $media->each(fn($m) => $m->setHidden(array_merge($m->getHidden(), ['latitude', 'longitude', 'altitude'])));
        }

        return Inertia::render('Shares/Show', [
            'link'  => [
                'name'              => $link->name,
                'allow_download'    => $link->allow_download,
                'allow_guest_upload' => $link->allow_guest_upload,
                'show_metadata'     => $link->show_metadata,
            ],
            'media' => $media,
        ]);
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();

        $password = $request->input('password');
        if (!$link->verifyPassword($password)) {
            return back()->withErrors(['password' => 'Nesprávné heslo.']);
        }

        session(["share_verified_{$token}" => true]);

        return redirect()->route('share.show', $token);
    }

    public function guestUpload(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();

        if (!$link->isAccessible() || !$link->allow_guest_upload) {
            return response()->json(['error' => 'Upload not allowed'], 403);
        }

        // Handle guest upload — store in a review inbox
        // Actual implementation deferred to GuestUploadJob
        return response()->json(['status' => 'queued']);
    }

    public function destroy(string $id): \Illuminate\Http\JsonResponse
    {
        $link = SharedLink::findOrFail($id);

        if ($link->created_by !== request()->user()->id) abort(403);

        AuditLog::record('share.delete', $link);
        $link->delete();

        return response()->json(['status' => 'deleted']);
    }
}
