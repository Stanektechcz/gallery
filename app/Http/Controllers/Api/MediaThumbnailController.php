<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Accepts a thumbnail the browser drew, for pictures this server cannot open.
 *
 * HEIC is why this exists. Every iPhone photographs in it, and converting one needs
 * ImageMagick built against libheif; without that the upload succeeds, the original is
 * safe, and the gallery shows a placeholder forever — which reads as a photograph that
 * failed rather than a missing codec.
 *
 * Deliberately a filler, never an override. If the server managed a thumbnail of its
 * own, that one is better: it was made from the full original with the EXIF rotation
 * applied, not from whatever the browser could decode. So a media item that already has
 * one is left exactly as it is.
 */
class MediaThumbnailController extends Controller
{
    /** A 400px JPEG is tens of kilobytes; anything near this is not one. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function store(Request $request, string $uuid): JsonResponse
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze náhledy ukládat.');

        $request->validate([
            'thumbnail' => 'required|file|mimes:jpg,jpeg|max:' . (self::MAX_BYTES / 1024),
        ]);

        // Scoped to spaces this person belongs to, so nobody can staple a picture of
        // their choosing onto somebody else's media.
        $spaceIds = $request->user()->gallerySpaces()->pluck('gallery_spaces.id');

        $media = MediaItem::where('uuid', $uuid)
            ->whereIn('gallery_space_id', $spaceIds)
            ->firstOrFail();

        // A film's still is filed as video_poster, a photograph's as thumbnail. Using one
        // name for both would leave ffmpeg's poster and the browser's frame sitting side
        // by side under different types, with nothing to say which is authoritative.
        $type = $media->media_type === 'video' ? 'video_poster' : 'thumbnail';

        if ($media->variants()->whereIn('type', ['thumbnail', 'video_poster'])->exists()) {
            return response()->json(['stored' => false, 'reason' => 'thumbnail_exists']);
        }

        $path = "media/{$media->uuid}/{$type}.jpg";
        $file = $request->file('thumbnail');

        if (! Storage::disk('public')->put($path, fopen($file->getRealPath(), 'rb'), 'public')) {
            return response()->json(['stored' => false, 'reason' => 'write_failed'], 500);
        }

        $media->variants()->create([
            'type' => $type,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => Storage::disk('public')->size($path),
            'width' => 400,
            'height' => 400,
        ]);

        return response()->json(['stored' => true]);
    }
}
