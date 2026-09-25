<?php

namespace App\Http\Controllers;

use App\Jobs\Media\GenerateImageVariantsJob;
use App\Jobs\Media\GenerateVideoPosterJob;
use App\Models\MediaItem;
use App\Services\Auth\PristupDoGalerie;
use App\Support\SpaceContext;
use App\Support\Trezor;
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

        /*
         * The extension may arrive as ?ext= instead of on the path; see
         * MediaVariant::proxyUrl for why. Restricted to plain letters and digits so the
         * query cannot be used to reach a different file than the path names.
         */
        $extension = (string) $request->query('ext', '');
        if ($extension !== '' && preg_match('/^[a-z0-9]{2,5}$/i', $extension)) {
            $path .= '.'.strtolower($extension);
        }

        /*
         * Kdo soubor dostane.
         *
         * Tahle adresa vydávala cokoli z veřejného disku **bez přihlášení**
         * a s roční veřejnou cache: originály fotek, fotky v koši i v trezoru,
         * hlasovky hostů. Stačilo znát uuid — a to je v každém sdíleném odkazu,
         * v protokolu i v historii prohlížeče. Zrušení sdíleného odkazu tak
         * nezrušilo nic: adresa originálu fungovala dál.
         *
         * Projde podepsaná adresa (vydává ji aplikace, platí nejvýš do zítřka),
         * nebo přihlášený člen prostoru, kterému soubor patří. Cizí i neexistující
         * soubor dostane totéž 404 — odpověď neprozradí, že tu něco je.
         */
        if (! $this->smi($request, $path)) {
            abort(404);
        }

        if (! Storage::disk('public')->exists($path)) {
            $fallback = $this->missingPreviewResponse($path);
            if ($fallback) {
                return $fallback;
            }

            abort(404);
        }

        // Do not make finfo inspect a multi-gigabyte video before range streaming can
        // begin. Known gallery formats have an unambiguous extension; finfo remains a
        // fallback only for uncommon files.
        $mimeType = $this->mimeTypeForPath($path);
        $size = Storage::disk('public')->size($path);
        $lastMod = Storage::disk('public')->lastModified($path);

        // ETag / conditional GET support
        $etag = md5($path.$lastMod);
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

        /*
         * Soubor z trezoru se neukládá ani do mezipaměti prohlížeče.
         *
         * S `max-age` na den ho prohlížeč po zamčení trezoru ukázal z paměti,
         * aniž by se serveru zeptal — zámek by platil jen pro soubory, které
         * ještě nikdo neotevřel. Soukromé fotky nepatří ani do sdílených
         * mezipamětí (proxy, CDN), proto u ostatních `private`.
         */
        $mezipamet = $this->zTrezoru($path) ? 'private, no-store' : 'private, max-age=86400';

        return response()->stream(function () use ($path, $start, $length) {
            $filePath = Storage::disk('public')->path($path);
            $stream = @fopen($filePath, 'rb');
            if (! $stream) {
                return;
            }

            fseek($stream, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($stream);
        }, $status, array_filter([
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Content-Range' => $range === null ? null : "bytes {$start}-{$end}/{$size}",
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => $mezipamet,
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastMod).' GMT',
            'X-Content-Type-Options' => 'nosniff',
            // SVG z disku je obrázek, ne stránka: skript v něm se nespustí.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]));
    }

    /**
     * Podepsaná adresa, nebo přihlášený člen prostoru — viz `serve()`.
     *
     * Trezorová fotka se členovi vydá, jen když má trezor v sezení odemčený
     * (stejný klíč, jaký hlídá `ProtectVaultMedia`).
     */
    private function smi(Request $request, string $path): bool
    {
        if ($request->hasValidSignature()) {
            /*
             * I podepsaná adresa se u trezoru ptá na odemčení.
             *
             * Podpis platí do konce zítřka. Fotka, která mezitím odešla do
             * trezoru, by přes adresu vydanou dřív (oblíbené, archiv, sdílená
             * stránka) šla otevřít dál — i se zamčeným trezorem a bez přihlášení.
             */
            if (preg_match('#^(?:media|variants)/([0-9a-f-]{36})/#i', $path, $shoda)) {
                $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                    ->withTrashed()
                    ->where('uuid', $shoda[1])
                    ->first(['is_hidden', 'trashed_at', 'deleted_at']);

                /*
                 * Fotka v koši (nebo smazaná) podpisem neprojde.
                 *
                 * Větev s podpisem hlídala jen trezor. Fotka vyhozená do koše
                 * tak přes adresu vydanou dřív (sdílená stránka, notifikace,
                 * historie prohlížeče) šla otevřít dál, bez přihlášení. O ní
                 * rozhoduje členství níž — dvojice svůj koš vidí dál, host ne.
                 *
                 * Návrh na smazání (`trash_requested_at`) fotku nepřesouvá: dokud
                 * ho druhý neschválí, je normálně v galerii a adresy platí.
                 */
                if ($media === null || ($media->trashed_at === null && $media->deleted_at === null)) {
                    return ! $media?->is_hidden || $this->trezorOdemceny($request);
                }
            } else {
                return true;
            }
        }

        $user = $request->user('sanctum') ?? $request->user();

        if ($user === null || $user->is_active === false) {
            return false;
        }

        if (preg_match('#^(?:media|variants)/([0-9a-f-]{36})/#i', $path, $shoda)) {
            $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('uuid', $shoda[1])
                ->first(['id', 'gallery_space_id', 'is_hidden']);

            if ($media === null || ! $this->clen($user, (int) $media->gallery_space_id)) {
                return false;
            }

            return ! $media->is_hidden || $this->trezorOdemceny($request);
        }

        if (preg_match('#^hlasovky/(\d+)/#', $path, $shoda)) {
            return $this->clen($user, (int) $shoda[1]);
        }

        // Neznámé místo na disku bez podpisu nikomu.
        return false;
    }

    /** Odemčený pro toho, kdo je přihlášený (i tokenem) — viz `Trezor`. */
    private function trezorOdemceny(Request $request): bool
    {
        return Trezor::odemcen($request, $request->user('sanctum') ?? $request->user());
    }

    /**
     * Patří soubor fotce z trezoru?
     *
     * Podle ní se řídí mezipaměť — viz `serve()`.
     */
    private function zTrezoru(string $path): bool
    {
        if (! preg_match('#^(?:media|variants)/([0-9a-f-]{36})/#i', $path, $shoda)) {
            return false;
        }

        return (bool) MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('uuid', $shoda[1])
            ->value('is_hidden');
    }

    /**
     * Člen dvojice prostoru, ne host.
     *
     * Host (`viewer`/`contributor`) má fotky jen z odkazu, který dostane —
     * a ten vydává podepsané adresy. Bez podpisu by si přes uuid otevřel
     * originál i po zrušení odkazu.
     */
    private function clen($user, int $prostor): bool
    {
        $clenstvi = $user->gallerySpaces()->where('gallery_spaces.id', $prostor)->first();

        return $clenstvi !== null
            && ((int) $clenstvi->owner_id === (int) $user->id
                || in_array((string) $clenstvi->pivot->role, PristupDoGalerie::ROLE_DVOJICE, true));
    }

    private function mimeTypeForPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $known = [
            'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
            'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'm3u8' => 'application/vnd.apple.mpegurl', 'ts' => 'video/mp2t',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif', 'svg' => 'image/svg+xml',
        ];

        return $known[$extension] ?? (Storage::disk('public')->mimeType($path) ?: 'application/octet-stream');
    }

    /**
     * Broken historical preview records must not make the whole grid issue a
     * wall of 404s. Return a lightweight placeholder immediately and queue a
     * single repair per item. Originals remain protected by their own routes;
     * this deliberately applies only to preview filenames.
     */
    private function missingPreviewResponse(string $path): ?\Illuminate\Http\Response
    {
        if (! preg_match('#^media/([0-9a-f-]{36})/(thumbnail|video_poster)\.(?:jpe?g|png|webp)$#i', $path, $match)) {
            return null;
        }

        // Without the space scope, deliberately.
        //
        // This runs on an image request, where the tenant context the rest of the app
        // relies on is not established — so the scoped lookup found nothing, the fallback
        // gave up, and every missing preview answered 404. A page of broken images is a
        // gallery that looks destroyed rather than one waiting for a thumbnail.
        //
        // Reading a uuid to decide which placeholder to draw leaks nothing: the response
        // is the same grey rectangle either way, and the file itself is still served by
        // the guarded path above.
        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('uuid', $match[1])
            ->first();

        if (! $media) {
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
            .'<rect width="800" height="450" fill="'.$background.'"/>'
            .'<circle cx="400" cy="225" r="105" fill="#7c3aed"/>'.$symbol.'</svg>';

        return response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Gallery-Preview-Repair' => 'queued',
        ]);
    }

    /** @return array{int, int}|null|false Valid range, no range, or malformed range. */
    private function parseRange(?string $header, int $size): array|null|false
    {
        if (! $header) {
            return null;
        }
        if ($size < 1 || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match)) {
            return false;
        }

        [$whole, $startRaw, $endRaw] = $match;
        if ($startRaw === '' && $endRaw === '') {
            return false;
        }

        if ($startRaw === '') {
            $length = (int) $endRaw;
            if ($length < 1) {
                return false;
            }

            return [max(0, $size - $length), $size - 1];
        }

        $start = (int) $startRaw;
        $end = $endRaw === '' ? $size - 1 : min((int) $endRaw, $size - 1);

        return $start >= $size || $start > $end ? false : [$start, $end];
    }
}
