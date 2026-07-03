<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaFileController extends Controller
{
    /**
     * Serve a public-disk media file directly via Laravel (bypasses Apache symlink).
     * GET /files/{path}   where path = media/{uuid}/thumbnail.jpg etc.
     */
    public function serve(Request $request, string $path): StreamedResponse|\Illuminate\Http\Response
    {
        // Prevent path traversal
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            abort(400);
        }

        if (!Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $mimeType = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';
        $size     = Storage::disk('public')->size($path);
        $lastMod  = Storage::disk('public')->lastModified($path);

        // ETag / conditional GET support
        $etag = md5($path . $lastMod);
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304);
        }

        return response()->stream(function () use ($path) {
            $stream = Storage::disk('public')->readStream($path);
            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type'   => $mimeType,
            'Content-Length' => $size,
            'Cache-Control'  => 'public, max-age=31536000, immutable',
            'ETag'           => $etag,
            'Last-Modified'  => gmdate('D, d M Y H:i:s', $lastMod) . ' GMT',
        ]);
    }
}
