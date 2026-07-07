<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\GuestUpload;
use App\Models\SharedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $spaceId = $request->user()->gallerySpaces()->first()->id;
        $mediaIds = collect($data['media_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $validMediaIds = MediaItem::where('gallery_space_id', $spaceId)
            ->where('is_hidden', false)->whereNull('trashed_at')->whereIn('id', $mediaIds)->pluck('id');
        if ($validMediaIds->count() !== $mediaIds->count()) {
            throw ValidationException::withMessages(['media_ids' => 'Výběr obsahuje nedostupnou nebo soukromou položku.']);
        }
        if (($data['target_type'] ?? null) === 'media') {
            $validTarget = MediaItem::where('gallery_space_id', $spaceId)->where('is_hidden', false)->whereNull('trashed_at')->where('id', $data['target_id'] ?? 0)->exists();
            if (! $validTarget) throw ValidationException::withMessages(['target_id' => 'Médium nelze sdílet.']);
        }
        if (($data['target_type'] ?? null) === 'album') {
            $validTarget = \App\Models\Album::where('gallery_space_id', $spaceId)->where('id', $data['target_id'] ?? 0)->exists();
            if (! $validTarget) throw ValidationException::withMessages(['target_id' => 'Album nelze sdílet.']);
        }

        $link = SharedLink::create([
            'created_by'        => $request->user()->id,
            'gallery_space_id'  => $spaceId,
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

        if ($validMediaIds->isNotEmpty()) {
            $link->mediaItems()->attach($validMediaIds);
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
        DB::table('share_access_logs')->insert(['shared_link_id' => $link->id, 'action' => 'view', 'ip_hash' => hash('sha256', (string) $request->ip() . config('app.key')), 'user_agent_family' => Str::limit((string) $request->userAgent(), 80, ''), 'created_at' => now()]);

        $media = match ($link->target_type) {
            'album'     => MediaItem::where('primary_album_id', $link->target_id)->where('is_hidden', false)->with('variants')->limit(100)->get(),
            'media'     => MediaItem::where('id', $link->target_id)->where('is_hidden', false)->with('variants')->get(),
            'selection' => $link->mediaItems()->where('is_hidden', false)->with('variants')->get(),
            default     => collect(),
        };

        // Strip GPS if configured
        if ($link->hide_gps) {
            $media->each(fn($m) => $m->setHidden(array_merge($m->getHidden(), ['latitude', 'longitude', 'altitude'])));
        }

        return Inertia::render('Shares/Show', [
            'link'  => [
                'token'             => $link->token,
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

        $password = (string) $request->input('password', '');
        if (!$link->verifyPassword($password)) {
            return back()->withErrors(['password' => 'Nesprávné heslo.']);
        }

        session(["share_verified_{$token}" => true]);

        return redirect()->route('share.show', $token);
    }

    public function guestUpload(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();

        if (!$link->isAccessible() || !$link->allow_guest_upload || ($link->password_hash && ! session("share_verified_{$token}"))) {
            return response()->json(['error' => 'Upload not allowed'], 403);
        }
        $limitKb = (int) floor(min($link->upload_limit_bytes ?: 104857600, 104857600) / 1024);
        $data = $request->validate(['files' => 'required|array|min:1|max:20', 'files.*' => "required|file|max:{$limitKb}|mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime", 'contributor_name' => 'nullable|string|max:100']);
        $uploads = collect($data['files'])->map(function ($file) use ($link, $data) {
            $uuid = (string) Str::uuid();
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs("guest_uploads/{$uuid}", $safeName, 'local');
            return GuestUpload::create(['uuid' => $uuid, 'shared_link_id' => $link->id, 'original_filename' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'storage_path' => $path, 'contributor_name' => $data['contributor_name'] ?? null]);
        });
        DB::table('share_access_logs')->insert(['shared_link_id' => $link->id, 'action' => 'guest_upload', 'ip_hash' => hash('sha256', (string) $request->ip() . config('app.key')), 'user_agent_family' => Str::limit((string) $request->userAgent(), 80, ''), 'created_at' => now()]);

        return response()->json(['status' => 'pending_review', 'count' => $uploads->count()], 201);
    }

    public function download(Request $request, string $token, string $uuid): StreamedResponse
    {
        $link = SharedLink::where('token', $token)->firstOrFail();
        abort_unless($link->isAccessible() && $link->allow_download && (! $link->password_hash || session("share_verified_{$token}")), 403);
        $media = match ($link->target_type) {
            'album' => MediaItem::where('primary_album_id', $link->target_id),
            'media' => MediaItem::where('id', $link->target_id),
            'selection' => $link->mediaItems(),
            default => MediaItem::whereRaw('1 = 0'),
        };
        $item = $media->where('uuid', $uuid)->where('is_hidden', false)->whereNull('trashed_at')->firstOrFail();
        $variant = $item->variants()->where('type', 'original')->firstOrFail();
        DB::table('share_access_logs')->insert(['shared_link_id' => $link->id, 'action' => 'download', 'ip_hash' => hash('sha256', (string) $request->ip() . config('app.key')), 'media_item_id' => $item->id, 'created_at' => now()]);

        return \Illuminate\Support\Facades\Storage::disk($variant->disk)->download($variant->path, $item->original_filename);
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
