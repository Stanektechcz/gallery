<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
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

        $range = $this->parseRange($request->header('Range'), $size);
        if ($range === false) {
            return response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                'Content-Range' => "bytes */{$size}",
                'Accept-Ranges' => 'bytes',
            ]);
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $length = $end - $start + 1;
        $status = $range === null ? Response::HTTP_OK : Response::HTTP_PARTIAL_CONTENT;

        return response()->stream(function () use ($path, $start, $length) {
            $filePath = Storage::disk('public')->path($path);
            $stream = @fopen($filePath, 'rb');
            if (!$stream) return;

            fseek($stream, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($stream)) {
                $chunk = fread($stream, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($stream);
        }, $status, array_filter([
            'Content-Type'   => $mimeType,
            'Content-Length' => $length,
            'Content-Range'  => $range === null ? null : "bytes {$start}-{$end}/{$size}",
            'Accept-Ranges'  => 'bytes',
            'Cache-Control'  => 'public, max-age=31536000, immutable',
            'ETag'           => $etag,
            'Last-Modified'  => gmdate('D, d M Y H:i:s', $lastMod) . ' GMT',
        ]));
    }

    /** @return array{int, int}|null|false Valid range, no range, or malformed range. */
    private function parseRange(?string $header, int $size): array|null|false
    {
        if (!$header) return null;
        if ($size < 1 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match)) return false;

        [$whole, $startRaw, $endRaw] = $match;
        if ($startRaw === '' && $endRaw === '') return false;

        if ($startRaw === '') {
            $length = (int) $endRaw;
            if ($length < 1) return false;
            return [max(0, $size - $length), $size - 1];
        }

        $start = (int) $startRaw;
        $end = $endRaw === '' ? $size - 1 : min((int) $endRaw, $size - 1);

        return $start >= $size || $start > $end ? false : [$start, $end];
    }
}
