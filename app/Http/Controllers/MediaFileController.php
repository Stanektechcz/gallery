<?php

namespace App\Http\Controllers;

use App\Jobs\Media\GenerateImageVariantsJob;
use App\Jobs\Media\GenerateVideoPosterJob;
use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            $fallback = $this->missingPreviewResponse($path);
            if ($fallback) {
                return $fallback;
            }

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

    /**
     * Broken historical preview records must not make the whole grid issue a
     * wall of 404s. Return a lightweight placeholder immediately and queue a
     * single repair per item. Originals remain protected by their own routes;
     * this deliberately applies only to preview filenames.
     */
    private function missingPreviewResponse(string $path): ?\Illuminate\Http\Response
    {
        if (!preg_match('#^media/([0-9a-f-]{36})/(thumbnail|video_poster)\.(?:jpe?g|png|webp)$#i', $path, $match)) {
            return null;
        }

        $media = MediaItem::where('uuid', $match[1])->first();
        if (!$media) {
            return null;
        }

        $cacheKey = "gallery:preview-repair:{$media->id}";
        if (Cache::add($cacheKey, true, now()->addMinutes(5))) {
            if ($media->media_type === 'video') {
                GenerateVideoPosterJob::dispatch($media->id)->onQueue('media');
            } elseif ($media->media_type === 'photo') {
                GenerateImageVariantsJob::dispatch($media->id)->onQueue('media');
            }
        }

        $isVideo = $media->media_type === 'video';
        $background = $isVideo ? '#171725' : '#20202d';
        $symbol = $isVideo
            ? '<path d="M355 175v100l90-50z" fill="white"/>'
            : '<path d="M245 150h310v150H245z" fill="none" stroke="white" stroke-width="18"/><circle cx="330" cy="205" r="24" fill="white"/><path d="m260 285 80-78 55 50 35-31 90 59z" fill="white"/>';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450">'
            . '<rect width="800" height="450" fill="' . $background . '"/>'
            . '<circle cx="400" cy="225" r="105" fill="#7c3aed"/>' . $symbol . '</svg>';

        return response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Gallery-Preview-Repair' => 'queued',
        ]);
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
