<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MediaController extends Controller
{
    public function show(Request $request, string $uuid): Response
    {
        $media = MediaItem::where('uuid', $uuid)
            ->with(['variants', 'tags', 'people', 'places', 'primaryAlbum', 'albums', 'currentEdit'])
            ->firstOrFail();

        Gate::authorize('view', $media);

        $album      = $media->primaryAlbum;
        $breadcrumb = $album ? $album->breadcrumb : [];

        // Attach URL to each variant (uses model getUrlAttribute, explicit here for clarity)
        $media->variants->each(function ($v) {
            $v->url = $v->disk === 'public'
                ? url('/files/' . ltrim($v->path, '/'))
                : url('media-stream/' . $v->path);
        });

        $prevNext = $this->getPrevNext($media);

        // Per-user data for the current viewer
        $user       = $request->user();
        $memberIds  = $user->gallerySpaces()->first()->members()->pluck('users.id');

        $isMyFav = DB::table('user_favorites')
            ->where('user_id', $user->id)
            ->where('media_item_id', $media->id)
            ->exists();

        $favCount = DB::table('user_favorites')
            ->where('media_item_id', $media->id)
            ->whereIn('user_id', $memberIds)
            ->count();
        $isShared = $memberIds->count() > 1 && $favCount >= $memberIds->count();

        $myRating = DB::table('user_ratings')
            ->where('user_id', $user->id)
            ->where('media_item_id', $media->id)
            ->value('rating');

        // Merge per-user fields into the serialized media array
        $mediaData                       = $media->toArray();
        $mediaData['is_my_favorite']     = $isMyFav;
        $mediaData['is_shared_favorite'] = $isShared;
        $mediaData['my_rating']          = $myRating;

        return Inertia::render('Media/Show', [
            'media'      => $mediaData,
            'breadcrumb' => $breadcrumb,
            'prev'       => $prevNext['prev'],
            'next'       => $prevNext['next'],
        ]);
    }

    public function apiShow(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->with(['variants', 'tags', 'people', 'places'])->firstOrFail();
        Gate::authorize('view', $media);
        return response()->json($media);
    }

    /**
     * GET /api/v1/media/compare?uuids=a,b,c,d
     * Return enriched data for up to 4 media items for side-by-side comparison.
     */
    public function compare(Request $request): JsonResponse
    {
        $uuids = array_values(array_filter(array_map('trim', explode(',', $request->input('uuids', '')))));
        $uuids = array_slice($uuids, 0, 4);

        if (empty($uuids)) {
            return response()->json([]);
        }

        $items = MediaItem::whereIn('uuid', $uuids)
            ->with(['variants'])
            ->get();

        // Preserve request order
        $ordered = collect($uuids)->map(fn($uuid) => $items->firstWhere('uuid', $uuid))->filter()->values();

        $user     = $request->user();
        $spaceId  = $user->gallerySpaces()->first()->id;

        // Per-user favorites for this user
        $myFavIds = DB::table('user_favorites')->where('user_id', $user->id)->pluck('media_item_id')->flip();
        $myRatings = DB::table('user_ratings')->where('user_id', $user->id)->whereIn('media_item_id', $ordered->pluck('id'))->pluck('rating', 'media_item_id');

        return response()->json($ordered->map(function ($m) use ($myFavIds, $myRatings) {
            Gate::authorize('view', $m);

            $thumb = $m->variants->first(fn($v) => $v->type === 'thumbnail')
                ?? $m->variants->first(fn($v) => $v->type === 'small')
                ?? $m->variants->first();

            $large = $m->variants->first(fn($v) => $v->type === 'large')
                ?? $m->variants->first(fn($v) => $v->type === 'medium')
                ?? $thumb;

            $makeUrl = fn($v) => $v ? ($v->disk === 'public' ? url('/files/' . ltrim($v->path, '/')) : url('media-stream/' . $v->path)) : null;

            return [
                'id'            => $m->id,
                'uuid'          => $m->uuid,
                'media_type'    => $m->media_type,
                'filename'      => $m->original_filename,
                'display_title' => $m->display_title,
                'taken_at'      => $m->taken_at?->toIso8601String(),
                'width'         => $m->width,
                'height'        => $m->height,
                'is_favorite'   => $m->is_favorite,
                'is_my_favorite' => $myFavIds->has($m->id),
                'rating'        => $m->rating,
                'my_rating'     => $myRatings->get($m->id, 0),
                'camera_make'   => $m->camera_make,
                'camera_model'  => $m->camera_model,
                'aperture'      => $m->aperture,
                'shutter_speed' => $m->shutter_speed,
                'iso'           => $m->iso,
                'focal_length'  => $m->focal_length,
                'full_url'      => "/media/{$m->uuid}/full",
                'thumb_url'     => $makeUrl($thumb),
                'large_url'     => $makeUrl($large),
            ];
        }));
    }

    /**
     * GET /api/v1/media/{uuid}/ratings
     * Return per-user ratings for all gallery space members.
     */
    public function ratings(Request $request, string $uuid): JsonResponse
    {
        $user  = $request->user();
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();

        $members = $user->gallerySpaces()->first()->members()->get(['users.id', 'users.name']);

        $userRatings = DB::table('user_ratings')
            ->where('media_item_id', $media->id)
            ->whereIn('user_id', $members->pluck('id'))
            ->get(['user_id', 'rating'])
            ->keyBy('user_id');

        $result = $members->map(fn($m) => [
            'user_id' => $m->id,
            'name'    => $m->name,
            'initial' => mb_strtoupper(mb_substr($m->name, 0, 1)),
            'rating'  => $userRatings->get($m->id)?->rating ?? 0,
            'is_me'   => $m->id === $user->id,
        ]);

        return response()->json($result);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $media);

        if ($request->user()->read_only_mode) {
            return response()->json(['error' => 'Read-only mode'], 403);
        }

        $data = $request->validate([
            'display_title' => 'nullable|string|max:512',
            'description'   => 'nullable|string',
            'caption'       => 'nullable|string',
            'notes'         => 'nullable|string',
            'rating'        => 'nullable|integer|min:0|max:5',
            'taken_at'      => 'nullable|date',
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'tag_ids'       => 'nullable|array',
            'tag_ids.*'     => 'integer|exists:tags,id',
            'person_ids'    => 'nullable|array',
            'person_ids.*'  => 'integer|exists:people,id',
        ]);

        $media->update(array_filter($data, fn($v, $k) => !in_array($k, ['tag_ids', 'person_ids']), ARRAY_FILTER_USE_BOTH));

        // Per-user rating: save to user_ratings table when rating is in request
        if (array_key_exists('rating', $data)) {
            $ratingVal = $data['rating'] ?? null;
            if ($ratingVal === null || $ratingVal === 0) {
                DB::table('user_ratings')
                    ->where('user_id', $request->user()->id)
                    ->where('media_item_id', $media->id)
                    ->delete();
            } else {
                DB::table('user_ratings')->updateOrInsert(
                    ['user_id' => $request->user()->id, 'media_item_id' => $media->id],
                    ['rating' => $ratingVal, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        if (isset($data['tag_ids'])) {
            $media->tags()->sync($data['tag_ids']);
        }
        if (isset($data['person_ids'])) {
            $media->people()->sync($data['person_ids']);
        }

        $media->rebuildSearchText();

        AuditLog::record('media.update', $media, array_keys($data));

        return response()->json($media->fresh()->load(['variants', 'tags', 'people', 'places']));
    }

    public function trash(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('delete', $media);

        if ($request->user()->read_only_mode) abort(403);

        $media->update([
            'trashed_at'  => now(),
            'purge_after' => now()->addDays((int) config('gallery.trash_retention_days', 30)),
        ]);

        AuditLog::record('media.trash', $media);

        return response()->json(['status' => 'trashed']);
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('restore', $media);

        $media->update(['trashed_at' => null, 'purge_after' => null]);

        AuditLog::record('media.restore', $media);

        return response()->json(['status' => 'restored']);
    }

    public function purge(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('delete', $media);

        AuditLog::record('media.purge', $media, ['filename' => $media->original_filename]);

        // Delete local files synchronously
        foreach ($media->variants as $variant) {
            if ($variant->disk === 'public') {
                Storage::disk('public')->delete($variant->path);
            }
        }
        Storage::disk('public')->deleteDirectory("media/{$media->uuid}");

        // Delete assembled source
        $uploadDir = storage_path("app/uploads/{$media->uuid}");
        if (is_dir($uploadDir)) {
            array_map('unlink', glob("$uploadDir/*") ?: []);
            @rmdir($uploadDir);
        }

        // Trash on Drive synchronously (best-effort)
        if ($media->drive_file_id) {
            try {
                $conn = \App\Models\StorageConnection::whereHas(
                    'owner',
                    fn($q) => $q->whereHas('gallerySpaces', fn($q2) => $q2->where('gallery_spaces.id', $media->gallery_space_id))
                )->where('provider', 'google_drive')->where('connection_status', 'healthy')->first();

                if ($conn) {
                    (new \App\Services\Storage\GoogleDriveStorageProvider($conn))->trash($media->drive_file_id);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Drive trash failed', ['error' => $e->getMessage()]);
            }
        }

        $media->forceDelete();

        return response()->json(['status' => 'purged']);
    }

    /**
     * GET /media/{uuid}/full
     * Serve full-resolution image inline (for viewer).
     * 1. Local original file (fast, no Drive API)
     * 2. Drive stream (if local missing — full quality guaranteed)
     */
    public function full(Request $request, string $uuid): mixed
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $media);

        // HEIC/HEIF and RAW originals are archival files. Browsers generally
        // cannot render them, so the viewer must prefer an already generated
        // high-quality web variant instead of returning an unrenderable 200.
        $needsBrowserVariant = in_array(strtolower($media->extension), [
            'heic', 'heif', 'dng', 'cr2', 'cr3', 'nef', 'arw', 'raf', 'orf', 'rw2',
        ], true);

        if ($needsBrowserVariant) {
            $browserFull = $media->variants()->where('type', 'browser_full')->first();
            if ($browserFull) {
                $path = Storage::disk($browserFull->disk)->path($browserFull->path);
                if (file_exists($path)) {
                    return response()->file($path, [
                        'Content-Type' => $browserFull->mime_type ?: 'image/webp',
                        'Cache-Control' => 'private, max-age=86400',
                        'X-Gallery-Viewer-Variant' => 'browser_full',
                    ]);
                }
            }

            // Generate a high-resolution browser copy even if an older 2560px
            // grid preview already exists. Otherwise historical HEIC images
            // would remain permanently limited to a preview resolution.
            $originalForPreview = $media->variants()->where('type', 'original')->first();
            if ($originalForPreview) {
                $sourcePath = Storage::disk($originalForPreview->disk)->path($originalForPreview->path);
                if (file_exists($sourcePath)) {
                    app(\App\Services\Media\ImageVariantService::class)->generateVariant(
                        $media,
                        $sourcePath,
                        'browser_full',
                        ['width' => 4096, 'quality' => 92],
                    );

                    $generated = $media->variants()->where('type', 'browser_full')->first();
                    if ($generated) {
                        $generatedPath = Storage::disk($generated->disk)->path($generated->path);
                        if (file_exists($generatedPath)) {
                            return response()->file($generatedPath, [
                                'Content-Type' => $generated->mime_type ?: 'image/webp',
                                'Cache-Control' => 'private, max-age=86400',
                                'X-Gallery-Viewer-Variant' => 'browser_full',
                            ]);
                        }
                    }
                }
            }

            // A source may be unavailable temporarily (for example while a
            // Drive recovery is pending). A smaller existing preview is still
            // more useful than a broken detail page.
            foreach (['large', 'medium', 'small', 'thumbnail'] as $type) {
                $variant = $media->variants()->where('type', $type)->first();
                if (!$variant) continue;
                $path = Storage::disk($variant->disk)->path($variant->path);
                if (file_exists($path)) {
                    return response()->file($path, [
                        'Content-Type' => $variant->mime_type ?: 'image/webp',
                        'Cache-Control' => 'private, max-age=86400',
                        'X-Gallery-Viewer-Variant' => $type,
                    ]);
                }
            }
        }

        // 1. Try local original first
        $original = $media->variants()->where('type', 'original')->first();
        if ($original) {
            $localPath = Storage::disk($original->disk)->path($original->path);
            if (file_exists($localPath)) {
                return response()->file($localPath, [
                    'Content-Type'  => $original->mime_type ?: $media->mime_type,
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            }
        }

        // 2. Stream from Drive (full original quality, no local file needed)
        if ($media->drive_file_id) {
            $connection = \App\Models\StorageConnection::whereHas(
                'owner',
                fn($q) => $q->whereHas('gallerySpaces', fn($q2) => $q2->where('gallery_spaces.id', $media->gallery_space_id))
            )->where('provider', 'google_drive')->where('connection_status', 'healthy')->first();

            if ($connection) {
                $provider = new \App\Services\Storage\GoogleDriveStorageProvider($connection);
                try {
                    $stream = $provider->download($media->drive_file_id);
                    $size   = $media->size_bytes;
                    return response()->stream(function () use ($stream) {
                        while (!$stream->eof()) {
                            echo $stream->read(65536);
                            flush();
                        }
                    }, 200, array_filter([
                        'Content-Type'   => $media->mime_type,
                        'Content-Length' => $size ?: null,
                        'Cache-Control'  => 'private, max-age=86400',
                    ]));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Drive full stream failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                }
            }
        }

        // 3. Fallback: any local variant
        foreach (['large', 'medium', 'small', 'thumbnail'] as $type) {
            $v = $media->variants()->where('type', $type)->first();
            if ($v) {
                $p = Storage::disk($v->disk)->path($v->path);
                if (file_exists($p)) {
                    return response()->file($p, ['Content-Type' => $v->mime_type ?? $media->mime_type]);
                }
            }
        }

        abort(404, 'Plné rozlišení není dostupné.');
    }

    public function download(Request $request, string $uuid): mixed
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $media);

        $wantOriginal = $request->boolean('original', false);

        if ($wantOriginal && $media->drive_file_id) {
            // Stream from Drive
            $connection = \App\Models\StorageConnection::where('owner_user_id', $media->owner_user_id)
                ->where('connection_status', 'healthy')
                ->first();

            if ($connection) {
                $provider = new \App\Services\Storage\GoogleDriveStorageProvider($connection);
                $stream   = $provider->download($media->drive_file_id);
                return response()->stream(function () use ($stream) {
                    while (!$stream->eof()) {
                        echo $stream->read(8192);
                        flush();
                    }
                }, 200, [
                    'Content-Type'        => $media->mime_type,
                    'Content-Disposition' => 'attachment; filename="' . $media->original_filename . '"',
                ]);
            }
        }

        // Serve local variant — try from largest to original
        $variant = $media->getVariant('large')
            ?? $media->getVariant('medium')
            ?? $media->getVariant('original');
        if ($variant) {
            $path = Storage::disk($variant->disk)->path($variant->path);
            if (file_exists($path)) {
                return response()->download($path, $media->original_filename);
            }
        }

        abort(404, 'Soubor není dostupný.');
    }

    public function stream(Request $request, string $uuid): mixed
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $media);

        // For videos, always prefer the compact, fast-start compatibility
        // file. It is served from the local cache with byte-range support.
        $compat = $media->getVariant('video_compat');
        if ($compat) {
            $path = Storage::disk($compat->disk)->path($compat->path);
            if (is_file($path)) {
                return response()->file($path, [
                    'Content-Type' => 'video/mp4',
                    'Cache-Control' => 'private, max-age=86400',
                    'Accept-Ranges' => 'bytes',
                ]);
            }
        }

        // A compatibility copy may still be processing. The local original is
        // preferable to a Drive proxy because the web server can honour seek
        // ranges and does not buffer the entire video before it starts.
        $original = $media->getVariant('original');
        if ($original) {
            $path = Storage::disk($original->disk)->path($original->path);
            if (is_file($path)) {
                return response()->file($path, [
                    'Content-Type' => $media->mime_type,
                    'Cache-Control' => 'private, max-age=86400',
                    'Accept-Ranges' => 'bytes',
                ]);
            }
        }

        // Stream from Drive
        if ($media->drive_file_id) {
            $connection = \App\Models\StorageConnection::where('owner_user_id', $media->owner_user_id)
                ->where('connection_status', 'healthy')->first();

            if ($connection) {
                $provider = new \App\Services\Storage\GoogleDriveStorageProvider($connection);
                $stream   = $provider->download($media->drive_file_id);
                return response()->stream(function () use ($stream) {
                    while (!$stream->eof()) {
                        echo $stream->read(8192);
                        flush();
                    }
                }, 200, ['Content-Type' => $media->mime_type]);
            }
        }

        abort(404);
    }

    public function toggleFavorite(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $media);

        $user    = $request->user();
        $exists  = $user->favorites()->where('media_item_id', $media->id)->exists();

        if ($exists) {
            $user->favorites()->detach($media->id);
            $isFav = false;
        } else {
            $user->favorites()->attach($media->id);
            $isFav = true;
        }

        return response()->json(['is_favorite' => $isFav]);
    }

    public function archive(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $media);

        $archived = !$media->is_archived;
        $media->update(['is_archived' => $archived]);

        AuditLog::record($archived ? 'media.archive' : 'media.unarchive', $media);

        return response()->json(['is_archived' => $archived]);
    }

    public function applyEdit(Request $request, string $uuid): JsonResponse
    {
        $media = MediaItem::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $media);

        $data = $request->validate([
            'operations' => 'required|array',
            'operations.*.type' => 'required|in:crop,rotate,mirror_h,mirror_v',
        ]);

        $latestEdit = $media->edits()->where('is_current', true)->first();
        $newVersion = ($latestEdit?->version ?? 0) + 1;

        $media->edits()->where('is_current', true)->update(['is_current' => false]);

        $edit = $media->edits()->create([
            'version'        => $newVersion,
            'operations_json' => $data['operations'],
            'is_current'     => true,
            'created_by'     => $request->user()->id,
            'created_at'     => now(),
        ]);

        // Queue regeneration of display variant
        \App\Jobs\Media\ApplyMediaEditJob::dispatch($media, $edit)->onQueue('media');

        return response()->json(['version' => $newVersion, 'status' => 'processing']);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action'       => 'required|in:trash,restore,archive,unarchive,favorite,unfavorite,tag,untag,add_to_album,move,add_person,add_place,rate,shift_date',
            // Accept UUIDs (frontend) or integer IDs (legacy)
            'uuids'        => 'nullable|array|max:500',
            'uuids.*'      => 'string',
            'media_ids'    => 'nullable|array|max:500',
            'media_ids.*'  => 'integer',
            // Action-specific fields
            'tag_id'       => 'nullable|integer|exists:tags,id',
            'album_id'     => 'nullable|integer|exists:albums,id',
            'album_uuid'   => 'nullable|string|exists:albums,uuid',
            'person_id'    => 'nullable|integer|exists:people,id',
            'place_id'     => 'nullable|integer|exists:places,id',
            'rating'       => 'nullable|integer|min:0|max:5',
            'hours_offset' => 'nullable|numeric',
        ]);

        $user = $request->user();

        // Resolve media: UUID list takes priority
        if (! empty($data['uuids'])) {
            $media = MediaItem::whereIn('uuid', $data['uuids'])->get();
        } else {
            $media = MediaItem::whereIn('id', $data['media_ids'] ?? [])->get();
        }

        // Resolve album by UUID if provided
        $album = null;
        if (! empty($data['album_uuid'])) {
            $album = \App\Models\Album::where('uuid', $data['album_uuid'])->first();
        } elseif (! empty($data['album_id'])) {
            $album = \App\Models\Album::find($data['album_id']);
        }

        $action       = $data['action'];
        $hoursOffset  = (float) ($data['hours_offset'] ?? 0);
        $ratingVal    = $data['rating'] ?? null;
        $processed    = 0;

        foreach ($media as $item) {
            try {
                Gate::authorize(in_array($action, ['trash']) ? 'delete' : 'update', $item);

                match ($action) {
                    'trash'        => $item->update(['trashed_at' => now(), 'purge_after' => now()->addDays(30)]),
                    'restore'      => $item->update(['trashed_at' => null, 'purge_after' => null]),
                    'archive'      => $item->update(['is_archived' => true]),
                    'unarchive'    => $item->update(['is_archived' => false]),

                    // Per-user favorites (use user_favorites table)
                    'favorite'     => DB::table('user_favorites')->insertOrIgnore(['user_id' => $user->id, 'media_item_id' => $item->id, 'created_at' => now()]),
                    'unfavorite'   => DB::table('user_favorites')->where('user_id', $user->id)->where('media_item_id', $item->id)->delete(),

                    'tag'          => $item->tags()->syncWithoutDetaching([$data['tag_id']]),
                    'untag'        => $item->tags()->detach($data['tag_id'] ?? []),

                    'add_to_album' => $album ? DB::table('album_media')->insertOrIgnore(['album_id' => $album->id, 'media_item_id' => $item->id, 'added_at' => now(), 'added_by' => $user->id]) : null,

                    'move'         => $album ? $item->update(['primary_album_id' => $album->id]) : null,

                    'add_person'   => isset($data['person_id']) ? $item->people()->syncWithoutDetaching([$data['person_id']]) : null,

                    'add_place'    => isset($data['place_id']) ? DB::table('media_place')->insertOrIgnore(['media_item_id' => $item->id, 'place_id' => $data['place_id'], 'is_primary' => false]) : null,

                    'rate'         => $ratingVal === null || $ratingVal === 0
                        ? DB::table('user_ratings')->where('user_id', $user->id)->where('media_item_id', $item->id)->delete()
                        : DB::table('user_ratings')->updateOrInsert(
                            ['user_id' => $user->id, 'media_item_id' => $item->id],
                            ['rating' => $ratingVal, 'updated_at' => now(), 'created_at' => now()]
                        ),

                    'shift_date'   => $hoursOffset != 0 && $item->taken_at
                        ? $item->update(['taken_at' => $item->taken_at->copy()->addSeconds((int) ($hoursOffset * 3600))])
                        : null,
                };

                $processed++;
            } catch (\Throwable $e) {
                // Skip items we can't process, continue with others
            }
        }

        // After favorite changes, update the is_favorite aggregate on media_items
        if (in_array($action, ['favorite', 'unfavorite'])) {
            foreach ($media as $item) {
                $anyFav = DB::table('user_favorites')->where('media_item_id', $item->id)->exists();
                $item->update(['is_favorite' => $anyFav]);
            }
        }

        return response()->json(['processed' => $processed]);
    }

    public function shareTarget(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Handle PWA Web Share Target — store files in session, redirect to album picker
        if ($request->hasFile('media')) {
            $request->session()->put(
                'share_target_files',
                collect($request->file('media'))->map(fn($f) => [
                    'name'     => $f->getClientOriginalName(),
                    'tmp_path' => $f->store('share_target', 'local'),
                    'mime'     => $f->getMimeType(),
                    'size'     => $f->getSize(),
                ])->values()->toArray()
            );
        }

        return redirect('/share-target');
    }

    /**
     * GET /share-target
     * Show the "save to album" picker for files received via Web Share Target.
     */
    public function showShareTarget(Request $request): \Inertia\Response
    {
        $files = $request->session()->get('share_target_files', []);

        $filesMeta = collect($files)->map(fn($f, $i) => [
            'index' => $i,
            'name'  => $f['name'],
            'mime'  => $f['mime'],
            'size'  => $f['size'],
        ])->values()->all();

        return Inertia::render('ShareTarget/Index', [
            'files' => $filesMeta,
        ]);
    }

    /**
     * GET /share-target/file/{index}
     * Serve a temp file stored during Web Share Target processing.
     */
    public function serveShareFile(Request $request, int $index): mixed
    {
        $files = $request->session()->get('share_target_files', []);
        if (! isset($files[$index])) {
            abort(404);
        }

        $path = Storage::disk('local')->path($files[$index]['tmp_path']);
        if (! file_exists($path)) {
            abort(404, 'Temp file no longer available');
        }

        return response()->file($path, [
            'Content-Type'        => $files[$index]['mime'],
            'Content-Disposition' => 'inline; filename="' . $files[$index]['name'] . '"',
        ]);
    }

    /**
     * DELETE /share-target
     * Clean up temp files and clear session after upload completes.
     */
    public function clearShareTarget(Request $request): \Illuminate\Http\JsonResponse
    {
        $files = $request->session()->pull('share_target_files', []);
        foreach ($files as $f) {
            Storage::disk('local')->delete($f['tmp_path']);
        }
        return response()->json(['ok' => true]);
    }

    private function getPrevNext(MediaItem $media): array
    {
        if (!$media->primary_album_id || !$media->taken_at) return ['prev' => null, 'next' => null];

        $query = MediaItem::where('primary_album_id', $media->primary_album_id)
            ->whereNull('trashed_at')
            ->where('status', 'ready');

        $prev = $query->clone()->where('taken_at', '<', $media->taken_at)
            ->orderBy('taken_at', 'desc')->select(['id', 'uuid'])->first();

        $next = $query->clone()->where('taken_at', '>', $media->taken_at)
            ->orderBy('taken_at', 'asc')->select(['id', 'uuid'])->first();

        return ['prev' => $prev, 'next' => $next];
    }
}
