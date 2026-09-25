<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Media\CalculateMediaHashesJob;
use App\Jobs\Media\InitiateDriveResumableUploadJob;
use App\Jobs\MirrorMediaToCloud;
use App\Models\Album;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\UploadChunk;
use App\Models\UploadSession;
use App\Notifications\GalleryNotification;
use App\Services\Billing\EntitlementService;
use App\Services\ExifExtractorService;
use App\Services\Media\FilenameMetadataService;
use App\Services\Media\MediaFormatService;
use App\Services\Media\VideoProcessingService;
use App\Support\Cas;
use App\Support\Trezor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

class UploadController extends Controller
{
    private const CHUNK_DISK = 'local';

    private const CHUNK_DIR = 'upload_chunks';

    /**
     * Strop jednoho bloku. Klient posílá 1MiB bloky; rezerva je pro starší
     * klienty. Bez stropu se kvóta hlídala jen podle ohlášené velikosti a
     * bloky samotné mohly zaplnit disk.
     */
    private const MAX_CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * Strop počtu bloků — 100 000 po 1 MiB je ~100 GB. Sloupec je
     * unsignedInteger, nad 4294967295 by MySQL vrátil 500.
     */
    private const MAX_CHUNKS = 100_000;

    /** Stavy média, které se chovají jako hotová fotka. */
    private const HOTOVE_STAVY = ['ready', 'received'];

    /** Přípona do `media_items.extension` (varchar 20) i do cesty na disku. */
    private const PRIPONA = '/^[a-z0-9]{1,10}$/';

    /**
     * POST /api/v1/uploads/check-duplicate
     * Check whether a file with given SHA-256 already exists in the gallery space.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $v = $request->validate([
            'sha256' => 'required|string|size:64',
            'target_album_id' => 'nullable|integer|exists:albums,id',
        ]);

        $user = $request->user();
        $space = $user->gallerySpaces()->firstOrFail();

        // Fotka v zamčeném trezoru pro tuhle odpověď neexistuje — jinak by
        // stačil hash souboru a odpověď by prozradila, že v trezoru je, i s názvem.
        $query = MediaItem::where('gallery_space_id', $space->id)
            ->where('sha256', $v['sha256'])
            ->whereNull('trashed_at');
        if (! Trezor::odemcen($request)) {
            $query->where('is_hidden', false);
        }
        $existing = $query
            ->orderByRaw("case when status in ('ready', 'received') then 0 else 1 end")
            ->first();

        // Selhaná nebo nedokončená kopie není duplicita: klient by soubor
        // přeskočil a v galerii by po něm nezbylo nic funkčního.
        if ($existing && ! in_array($existing->status, self::HOTOVE_STAVY, true)) {
            return response()->json([
                'exists' => false,
                'replacement_required' => true,
            ]);
        }

        if ($existing) {
            $addedToAlbum = false;
            if (! empty($v['target_album_id'])) {
                $album = $this->albumForSpace((int) $v['target_album_id'], $space->id);

                // Duplicitní soubor přeskočíme pouze tehdy, když jej uživatel
                // v daném albu opravdu uvidí. Samotné primary_album_id nebo
                // starý pivot nestačí: skrytý/selhaný soubor by jinak blokoval
                // nový upload a v albu by stále nic nebylo.
                if (! $this->isVisibleInAlbum($existing, $album)) {
                    if ($this->isReusableInAlbum($existing)) {
                        $addedToAlbum = $this->attachToAlbum($existing, $album, $user->id);
                    } else {
                        return response()->json([
                            'exists' => false,
                            'replacement_required' => true,
                            'reason' => 'Existující duplicitní soubor není v cílovém albu zobrazitelný; nahrává se nová funkční kopie.',
                        ]);
                    }
                }
            }

            return response()->json([
                'exists' => true,
                'media_uuid' => $existing->uuid,
                'filename' => $existing->original_filename,
                'added_to_album' => $addedToAlbum,
            ]);
        }

        return response()->json(['exists' => false]);
    }

    /**
     * Initiate a new resumable upload session.
     * POST /api/v1/uploads
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => 'required|string|max:512',
            'mime_type' => 'required|string|max:100',
            'total_size' => 'required|integer|min:1',
            'total_chunks' => 'required|integer|min:1|max:'.self::MAX_CHUNKS,
            'sha256' => 'nullable|string|size:64',
            'target_album_id' => 'nullable|integer|exists:albums,id',
            // The browser knows when the file was last written; we never will. Optional,
            // because an older client or a share-target upload may not send it.
            'client_modified_at' => 'nullable|date',
        ]);

        // Každý blok má aspoň bajt a nejvýš MAX_CHUNK_BYTES. Jiný poměr velikosti
        // a počtu bloků poctivý klient nepošle — a jen by otevřel cestu k tisícům
        // prázdných souborů na disku nebo k relaci, která nejde dokončit.
        $totalSize = (int) $validated['total_size'];
        $totalChunks = (int) $validated['total_chunks'];
        if ($totalChunks > $totalSize || $totalSize > $totalChunks * self::MAX_CHUNK_BYTES) {
            throw ValidationException::withMessages([
                'total_chunks' => 'Počet bloků neodpovídá velikosti souboru.',
            ]);
        }

        $user = $request->user();
        $space = $user->gallerySpaces()->firstOrFail();

        // Refuse before a single chunk is accepted, rather than half way through.
        $entitlements = app(EntitlementService::class);
        if (! $entitlements->canStore($space, (int) $validated['total_size'])) {
            $usage = $entitlements->storageUsage($space);
            abort(402, sprintf(
                'Tarif má limit %d GB a ten by se tímhle souborem překročil. Uvolněte místo nebo přejděte na vyšší tarif — viz /cenik.',
                (int) round(($usage['limit_mb'] ?? 0) / 1000)
            ));
        }

        if (! empty($validated['target_album_id'])) {
            $album = Album::whereKey($validated['target_album_id'])
                ->where('gallery_space_id', $space->id)
                ->whereNull('deleted_at')
                ->first();

            abort_unless($album, 422, 'Cílové album neexistuje nebo do něj nemáte přístup.');
        }

        $session = UploadSession::create([
            'user_id' => $user->id,
            'gallery_space_id' => $space->id,
            'target_album_id' => $validated['target_album_id'] ?? null,
            'original_filename' => $validated['filename'],
            'mime_type' => $validated['mime_type'],
            'total_size' => $validated['total_size'],
            'total_chunks' => $validated['total_chunks'],
            'sha256' => $validated['sha256'] ?? null,
            // Okamžik v UTC. Přetypování `datetime` by pásmo z řetězce zahodilo
            // a „+02:00" by se uložilo jako by to bylo UTC.
            'client_modified_at' => empty($validated['client_modified_at'])
                ? null
                : Carbon::parse($validated['client_modified_at'])->utc(),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'uuid' => $session->uuid,
            'total_chunks' => $session->total_chunks,
            'received_chunks' => 0,
            'status' => 'pending',
        ], 201);
    }

    /**
     * Upload a single chunk.
     * PUT /api/v1/uploads/{uuid}/chunks/{index}
     */
    public function uploadChunk(Request $request, string $uuid, int $index): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($index < 0 || $index >= $session->total_chunks) {
            return response()->json(['error' => 'Invalid chunk index'], 422);
        }

        if (! $request->hasFile('chunk')) {
            return response()->json([
                'error' => 'Blok souboru se nepodařilo přijmout.',
                'detail' => 'Blok překročil limit serveru nebo se přenos přerušil. Nahrávání používá bezpečné 1MiB bloky; zkuste soubor nahrát znovu.',
            ], 422);
        }

        $file = $request->file('chunk');
        if (! $file || ! $file->isValid()) {
            return response()->json([
                'error' => 'Blok souboru se nepodařilo přijmout.',
                'detail' => 'Zkontrolujte limit upload_max_filesize/post_max_size na serveru a zkuste nahrání znovu.',
            ], 422);
        }

        // Ještě před zápisem na disk: blok nad strop, nebo bloky, které by
        // dohromady přesáhly ohlášenou velikost (podle ní se hlídala kvóta).
        $chunkSize = (int) $file->getSize();
        if ($chunkSize > self::MAX_CHUNK_BYTES
            || $this->bajtyOstatnichBloku($session, $index) + $chunkSize > $session->total_size) {
            return $this->prilisVelkyBlok();
        }

        $chunkDir = self::CHUNK_DIR.'/'.$session->uuid;
        $path = $file->storeAs($chunkDir, "chunk_{$index}", self::CHUNK_DISK);

        // Checksum validation if provided
        $checksum = $request->input('checksum');
        if ($checksum && md5_file(Storage::disk(self::CHUNK_DISK)->path($path)) !== $checksum) {
            Storage::disk(self::CHUNK_DISK)->delete($path);

            return response()->json(['error' => 'Chunk checksum mismatch'], 422);
        }

        $chunk = UploadChunk::updateOrCreate(
            ['upload_session_id' => $session->id, 'chunk_index' => $index],
            [
                'path' => $path,
                'size_bytes' => $chunkSize,
                'checksum' => $checksum,
                'status' => 'received',
                'received_at' => now(),
            ]
        );

        // Souběžné bloky mohly kontrolou výše projít každý zvlášť. Kdo součet
        // přetáhl, jde pryč i se souborem — klient ho pošle znovu.
        if ($this->bajtyOstatnichBloku($session, $index) + $chunkSize > $session->total_size) {
            Storage::disk(self::CHUNK_DISK)->delete($path);
            $chunk->delete();
            $this->prepocitejPrijate($session);

            return $this->prilisVelkyBlok();
        }

        $receivedCount = $this->prepocitejPrijate($session);

        return response()->json([
            'chunk_index' => $index,
            'received_chunks' => $receivedCount,
            'total_chunks' => $session->total_chunks,
            'complete' => $receivedCount >= $session->total_chunks,
        ]);
    }

    /** Součet přijatých bloků kromě `$index` — ten se právě nahrazuje. */
    private function bajtyOstatnichBloku(UploadSession $session, int $index): int
    {
        return (int) UploadChunk::where('upload_session_id', $session->id)
            ->where('chunk_index', '!=', $index)
            ->sum('size_bytes');
    }

    /** Přepočítá přijaté bloky a bajty relace; vrací počet bloků. */
    private function prepocitejPrijate(UploadSession $session): int
    {
        $chunks = UploadChunk::where('upload_session_id', $session->id);
        $receivedCount = (clone $chunks)->count();
        $session->update([
            'received_chunks' => $receivedCount,
            'uploaded_bytes' => (int) (clone $chunks)->sum('size_bytes'),
        ]);

        return $receivedCount;
    }

    private function prilisVelkyBlok(): JsonResponse
    {
        $zprava = 'Blok souboru je větší, než nahrávání ohlásilo. Zkuste soubor nahrát znovu.';

        return response()->json(['error' => $zprava, 'message' => $zprava], 422);
    }

    /**
     * Get upload session status.
     * GET /api/v1/uploads/{uuid}
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->with('resultingMedia')
            ->firstOrFail();

        $receivedIndexes = $session->chunks()->pluck('chunk_index')->toArray();

        return response()->json([
            'uuid' => $session->uuid,
            'status' => $session->status,
            'total_chunks' => $session->total_chunks,
            'received_chunks' => $session->received_chunks,
            'uploaded_bytes' => $session->uploaded_bytes,
            'total_size' => $session->total_size,
            'received_indexes' => $receivedIndexes,
            'media_id' => $session->resulting_media_id,
            'expires_at' => $session->expires_at,
        ]);
    }

    /**
     * Finalize/complete upload session — triggers assembly job.
     * POST /api/v1/uploads/{uuid}/complete
     */
    public function complete(Request $request, string $uuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($session->status !== 'pending') {
            return $this->relaceUzZpracovana($session);
        }

        if (! $session->isComplete()) {
            return response()->json([
                'error' => 'Upload not complete',
                'received_chunks' => $session->received_chunks,
                'total_chunks' => $session->total_chunks,
            ], 422);
        }

        // Kvóta znovu až teď: mezi zahájením a dokončením mohla druhá nahrání
        // (nebo druhý člen dvojice) místo zaplnit.
        $space = GallerySpace::find($session->gallery_space_id);
        if ($space && ! app(EntitlementService::class)->canStore($space, (int) $session->total_size)) {
            $zprava = 'Soubor by překročil limit úložiště tarifu. Uvolněte místo nebo přejděte na vyšší tarif — viz /cenik.';

            return response()->json(['error' => $zprava, 'message' => $zprava], 402);
        }

        // Relaci si požadavek převezme jedním podmíněným zápisem. Kontrola stavu
        // a zápis „assembling" zvlášť nechaly dvojí „dokončit" (opakovaný
        // požadavek, dvě karty) založit dvě média z téhož souboru.
        $claimed = UploadSession::whereKey($session->id)
            ->where('status', 'pending')
            ->update(['status' => 'assembling', 'updated_at' => now()]);
        if ($claimed === 0) {
            return $this->relaceUzZpracovana($session->fresh() ?? $session);
        }
        $session->forceFill(['status' => 'assembling'])->syncOriginal();

        $media = null;
        $destPath = null;

        // Jedna očištěná přípona pro dočasný soubor, databázi i cestu originálu.
        // Název od uživatele patří do metadat, nikdy ale nesmí určovat cestu na
        // disku (ochrana před ../ i neplatnými znaky Windows).
        $ext = self::bezpecnaPripona($session->original_filename, $session->mime_type);

        // Assemble synchronously — no queue worker required
        try {
            $chunks = $session->chunks()->orderBy('chunk_index')->get();
            $destDir = storage_path("app/uploads/{$session->uuid}");
            @mkdir($destDir, 0755, true);
            $destPath = $destDir.'/source'.($ext !== '' ? ".{$ext}" : '');

            $destHandle = fopen($destPath, 'wb');
            if (! $destHandle) {
                throw new \RuntimeException("Cannot open output file: {$destPath}");
            }

            foreach ($chunks as $chunk) {
                $chunkPath = Storage::disk('local')->path($chunk->path);
                if (! file_exists($chunkPath)) {
                    throw new \RuntimeException("Missing chunk #{$chunk->chunk_index}");
                }
                $src = fopen($chunkPath, 'rb');
                stream_copy_to_stream($src, $destHandle);
                fclose($src);
            }
            fclose($destHandle);

            $assembledSize = filesize($destPath);
            if ($assembledSize !== $session->total_size) {
                throw new \RuntimeException("Size mismatch: expected {$session->total_size}, got {$assembledSize}");
            }

            $formatSvc = new MediaFormatService;
            $isRaw = MediaFormatService::isRaw($ext);
            $isVideo = MediaFormatService::isVideo($ext);
            $mediaType = ($isVideo || str_starts_with($session->mime_type, 'video/')) ? 'video' : 'photo';
            $filenameMetadata = (new FilenameMetadataService)
                ->infer($session->original_filename, $mediaType);

            // The archive is ordered by when a picture was taken, so a photograph with no
            // date at all falls into a heap at the end where nobody goes looking. When the
            // name held no date either, the file's own modification time stands in — the
            // last honest evidence we have. Real EXIF still wins: the metadata job runs
            // afterwards and overwrites this the moment it finds a genuine capture time.
            //
            // Prohlížeč posílá okamžik v UTC (toISOString), kdežto EXIF i datum z
            // názvu jsou hodiny fotoaparátu. Bez převodu by silvestrovská fotka
            // z 00:30 spadla do minulého roku.
            if (empty($filenameMetadata['taken_at']) && $session->client_modified_at) {
                $filenameMetadata['taken_at'] = Cas::mistni($session->client_modified_at)?->format('Y-m-d H:i:s');
            }

            // For RAW files: extract embedded JPEG preview for thumbnailing
            $previewPath = null;
            if ($isRaw) {
                $previewPath = $formatSvc->extractRawPreview($destPath);
            }

            // Instance napřed, uložení zvlášť: když selže posluchač `created`
            // (řádek už je vložený), musí ho úklid v catch najít a smazat.
            $media = new MediaItem([
                'gallery_space_id' => $session->gallery_space_id,
                'owner_user_id' => $session->user_id,
                'uploaded_by' => $session->user_id,
                'primary_album_id' => $session->target_album_id,
                'drive_file_id' => null,
                'drive_parent_folder_id' => null,
                'original_filename' => $session->original_filename,
                'safe_filename' => preg_replace('/[^a-zA-Z0-9._-]/', '_', $session->original_filename),
                'extension' => $ext,
                'mime_type' => $session->mime_type ?: ($isRaw ? MediaFormatService::rawMime($ext) : 'application/octet-stream'),
                'media_type' => $mediaType,
                'is_raw' => $isRaw,
                'raw_format' => $isRaw ? $ext : null,
                'size_bytes' => $assembledSize,
                'sha256' => $session->sha256,
                'status' => 'ready',
                'storage_status' => 'local_only',
                'uploaded_at' => now(),
                'last_verified_at' => now(),
                ...$filenameMetadata,
            ]);
            $media->save();

            // Store original file under public storage so it can be served
            $relPath = "media/{$media->uuid}/original".($ext !== '' ? ".{$ext}" : '');
            $stored = Storage::disk('public')->put(
                $relPath,
                fopen($destPath, 'rb'),
                'public'
            );
            if (! $stored) {
                throw new \RuntimeException("Failed to store file to public disk: {$relPath}");
            }

            // Register original variant
            $media->variants()->create([
                'type' => 'original',
                'disk' => 'public',
                'path' => $relPath,
                'mime_type' => $media->mime_type,
                'size_bytes' => $assembledSize,
                'width' => null,
                'height' => null,
            ]);

            // A copy to the space's own cloud, if it has one. Queued after the commit
            // rather than inside the transaction: a worker picking the job up first would
            // look for a media row that does not exist yet.
            $mediaId = $media->id;
            DB::afterCommit(function () use ($mediaId) {
                // Wrapped like every other dispatch in this method, and for a sharper
                // reason. This one sat inside the try whose catch deletes the media row
                // and wipes its directory — so a failure in the *backup copy* destroyed
                // the *original*. On a sync queue the job runs right here, in the
                // request, which turns any cloud hiccup into a lost photograph.
                try {
                    MirrorMediaToCloud::dispatch($mediaId);
                } catch (\Throwable $mirrorException) {
                    Log::warning('Kopii do cloudu se nepodařilo zařadit', [
                        'media_id' => $mediaId,
                        'error' => $mirrorException->getMessage(),
                    ]);
                }
            });

            // primary_album_id samotné nestačí pro části systému, které pracují
            // s explicitním obsahem alba (příběh, událost alba, sdílení). Vždy
            // proto založíme i členství v album_media a aktualizujeme souhrny.
            if ($session->target_album_id) {
                $this->attachToAlbum($media, $this->albumForSpace($session->target_album_id, $session->gallery_space_id), $session->user_id);
            }

            // Náhled musí být k dispozici hned po odpovědi nahrání; na frontu
            // zůstává až náročnější kompatibilní MP4 a další metadata.
            if ($media->media_type === 'photo') {
                $thumbSource = $previewPath ?? $destPath;
                $this->generateThumbnail($media, $thumbSource);
            } else {
                try {
                    $videoService = new VideoProcessingService;
                    $videoMetadata = $videoService->extractMetadata($destPath);
                    if ($videoMetadata) {
                        $media->update($videoMetadata);
                    }
                    $poster = $videoService->generatePoster($media, $destPath);
                    if (! $poster) {
                        $videoService->generateFallbackPoster($media);
                    }
                } catch (\Throwable $videoException) {
                    Log::warning('Immediate video preview failed; creating fallback', ['media_id' => $media->id, 'error' => $videoException->getMessage()]);
                    try {
                        (new VideoProcessingService)->generateFallbackPoster($media);
                    } catch (\Throwable $fallbackException) {
                        Log::error('Video fallback preview failed', ['media_id' => $media->id, 'error' => $fallbackException->getMessage()]);
                    }
                }
            }
            if ($previewPath) {
                @unlink($previewPath);
            }

            // Extract basic EXIF data synchronously (GPS + date + dimensions + panorama/live photo)
            if ($media->media_type === 'photo') {
                $this->extractBasicExif($media, $destPath, $formatSvc);
            }

            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
                'resulting_media_id' => $media->id,
                'assembled_path' => $destPath,
            ]);

            // Drive receives large originals in resumable chunks after the
            // client receives a successful upload response. This avoids
            // buffering an entire video in PHP and keeps the gallery usable.
            try {
                InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive');
            } catch (\Throwable $driveQueueException) {
                Log::warning('Drive synchronizaci se nepodařilo zařadit do fronty', ['media_id' => $media->id, 'error' => $driveQueueException->getMessage()]);
            }

            // Metadata a hash jsou doplňkové. Originál, základní metadata i
            // členství v albu už jsou hotové, proto případná nedostupnost
            // fronty/FFmpeg/GD nikdy nesmí zrušit úspěšné nahrání.
            try {
                CalculateMediaHashesJob::dispatch($media->id)->onQueue('media');
            } catch (\Throwable $processingException) {
                Log::warning('Deferred media processing could not start', [
                    'media_id' => $media->id,
                    'error' => $processingException->getMessage(),
                ]);
                // Podrobnosti jen do logu — pole se ukazuje v aplikaci.
                $media->update(['processing_error' => 'Doplňkové zpracování se nepodařilo spustit; bude možné ho spustit znovu.']);
            }

            // Cleanup chunk files
            Storage::disk('local')->deleteDirectory("upload_chunks/{$session->uuid}");

            AuditLog::record('media.upload', $media, ['filename' => $media->original_filename]);

            // Notify other space members about new upload
            try {
                $space = GallerySpace::find($session->gallery_space_id);
                if ($space) {
                    GalleryNotification::notifySpace(
                        $space,
                        $session->user_id,
                        'media.added',
                        request()->user()?->name.' přidal/a nové médium: '.$session->original_filename,
                        "/media/{$media->uuid}",
                        ['media_uuid' => $media->uuid],
                    );
                }
            } catch (\Throwable) { /* non-fatal */
            }

            return response()->json([
                'uuid' => $session->uuid,
                'status' => 'completed',
                'media_id' => $media->id,
                'media_uuid' => $media->uuid,
                // Told plainly, so the browser only bothers drawing a thumbnail for the
                // pictures this server could not open. Without it a folder of two
                // thousand JPEGs would be decoded twice over and posted back for nothing.
                //
                // A film's still is filed as video_poster, so asking only about
                // "thumbnail" would report every video as missing one and have the
                // browser redraw a frame ffmpeg had already produced.
                'has_thumbnail' => $media->variants()
                    ->whereIn('type', ['thumbnail', 'video_poster'])
                    ->exists(),
            ]);
        } catch (\Throwable $e) {
            if ($media?->exists) {
                Storage::disk('public')->deleteDirectory("media/{$media->uuid}");
                $media->forceDelete();
            }
            $session->update(['status' => 'failed']);
            Log::error('Upload assembly failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            // Text výjimky nesmí ven — nesl cesty na serveru i dotazy do databáze.
            $zprava = 'Soubor se nepodařilo dokončit. Zkuste ho nahrát znovu.';

            return response()->json(['error' => $zprava, 'message' => $zprava], 500);
        }
    }

    /**
     * Relace už je ve skládání, hotová, nebo selhala — druhé dokončení nic nezaloží.
     *
     * Hotová relace odpoví stejně jako první dokončení: klient, kterému
     * odpověď cestou vypadla (výpadek signálu), požadavek zopakuje a chyba
     * by u nahrané fotky hlásila, že se nenahrála.
     */
    private function relaceUzZpracovana(UploadSession $session): JsonResponse
    {
        $hotove = $session->status === 'completed' && $session->resulting_media_id
            ? MediaItem::find($session->resulting_media_id)
            : null;

        if ($hotove !== null) {
            return response()->json([
                'uuid' => $session->uuid,
                'status' => 'completed',
                'media_id' => $hotove->id,
                'media_uuid' => $hotove->uuid,
                'has_thumbnail' => $hotove->variants()
                    ->whereIn('type', ['thumbnail', 'video_poster'])
                    ->exists(),
            ]);
        }

        $zprava = $session->status === 'completed'
            ? 'Tenhle soubor už je nahraný.'
            : 'Nahrávání tohohle souboru se už dokončuje nebo skončilo chybou. Zkuste ho nahrát znovu.';

        return response()->json([
            'error' => $zprava,
            'message' => $zprava,
            'status' => $session->status,
            'media_id' => $session->resulting_media_id,
        ], 409);
    }

    /**
     * Generate a JPEG thumbnail — tries Imagick first, then GD.
     * If both fail, creates a thumbnail alias pointing to the original.
     */
    private function generateThumbnail(MediaItem $media, string $sourcePath): void
    {
        $thumbRel = "media/{$media->uuid}/thumbnail.jpg";
        $size = 400;

        // --- Try Imagick ---
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick($sourcePath);
                $im->setIteratorIndex(0);
                $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                $im->autoOrient();

                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                $min = min($w, $h);
                $im->cropImage($min, $min, (int) (($w - $min) / 2), (int) (($h - $min) / 2));
                $im->thumbnailImage($size, $size);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);

                $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_thumb_').'.jpg';
                $im->writeImage($tmpPath);
                $im->destroy();

                $stored = Storage::disk('public')->put($thumbRel, fopen($tmpPath, 'rb'));
                @unlink($tmpPath);

                if ($stored) {
                    $media->variants()->create([
                        'type' => 'thumbnail',
                        'disk' => 'public',
                        'path' => $thumbRel,
                        'mime_type' => 'image/jpeg',
                        'size_bytes' => Storage::disk('public')->size($thumbRel),
                        'width' => $size,
                        'height' => $size,
                    ]);

                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('Imagick thumbnail failed, trying GD', ['media_id' => $media->id, 'error' => $e->getMessage()]);
            }
        }

        // --- Try GD ---
        if (extension_loaded('gd')) {
            try {
                $ext = strtolower($media->extension);
                $src = match ($ext) {
                    'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
                    'png' => @imagecreatefrompng($sourcePath),
                    'webp' => @imagecreatefromwebp($sourcePath),
                    'gif' => @imagecreatefromgif($sourcePath),
                    default => null,
                };

                if ($src) {
                    if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'])) {
                        $exif = @exif_read_data($sourcePath);
                        $orientation = $exif['Orientation'] ?? 1;
                        if ($orientation === 6) {
                            $src = imagerotate($src, -90, 0);
                        } elseif ($orientation === 3) {
                            $src = imagerotate($src, 180, 0);
                        } elseif ($orientation === 8) {
                            $src = imagerotate($src, 90, 0);
                        }
                    }

                    $origW = imagesx($src);
                    $origH = imagesy($src);
                    $min = min($origW, $origH);
                    $cropX = (int) (($origW - $min) / 2);
                    $cropY = (int) (($origH - $min) / 2);

                    $thumb = imagecreatetruecolor($size, $size);
                    imagecopyresampled($thumb, $src, 0, 0, $cropX, $cropY, $size, $size, $min, $min);
                    imagedestroy($src);

                    $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_thumb_').'.jpg';
                    imagejpeg($thumb, $tmpPath, 85);
                    imagedestroy($thumb);

                    $stored = Storage::disk('public')->put($thumbRel, fopen($tmpPath, 'rb'), 'public');
                    @unlink($tmpPath);

                    if ($stored) {
                        $media->variants()->create([
                            'type' => 'thumbnail',
                            'disk' => 'public',
                            'path' => $thumbRel,
                            'mime_type' => 'image/jpeg',
                            'size_bytes' => Storage::disk('public')->size($thumbRel),
                            'width' => $size,
                            'height' => $size,
                        ]);

                        return;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('GD thumbnail failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);
            }
        }

        // --- Fallback: alias thumbnail = original ---
        //
        // Only for formats a browser will actually draw. Pointing a thumbnail at a HEIC
        // — which is what every iPhone uploads by default — produced a broken image in
        // every grid on every browser but Safari, and it looked like the photograph had
        // failed to upload rather than like a server missing a codec. Same for RAW and
        // TIFF, which no browser renders either.
        //
        // Leaving no thumbnail is the better answer: the file controller draws a proper
        // placeholder for a missing one and queues another attempt at making it, so the
        // picture appears by itself once the server can produce it.
        $displayable = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

        if (! in_array(strtolower($media->extension), $displayable, true)) {
            Log::warning('Thumbnail could not be produced and the original is not browser-displayable', [
                'media_id' => $media->id,
                'extension' => $media->extension,
                'imagick' => extension_loaded('imagick'),
                'gd' => extension_loaded('gd'),
            ]);

            return;
        }

        $originalVar = $media->variants()->where('type', 'original')->first();
        if ($originalVar) {
            $media->variants()->create([
                'type' => 'thumbnail',
                'disk' => $originalVar->disk,
                'path' => $originalVar->path,
                'mime_type' => $originalVar->mime_type,
                'size_bytes' => $originalVar->size_bytes,
                'width' => null,
                'height' => null,
            ]);
            Log::info('Thumbnail aliased to original (no GD/Imagick)', ['media_id' => $media->id]);
        }
    }

    /**
     * Extract GPS, date, dimensions via ExifExtractorService.
     * Also detects panorama/360° and Live Photo pairs.
     */
    private function extractBasicExif(
        MediaItem $media,
        string $sourcePath,
        ?MediaFormatService $formatSvc = null
    ): void {
        $formatSvc ??= new MediaFormatService;

        try {
            $exifSvc = new ExifExtractorService;
            $data = $exifSvc->extract($sourcePath);
            $rawExif = $exifSvc->getRawExif($sourcePath);   // get the full raw EXIF for extended detection

            if ($data) {
                $media->update(array_filter($data, fn ($v) => $v !== null));
            }

            // Panorama / 360° detection
            $panData = $formatSvc->detectPanorama(
                $rawExif ?? [],
                $media->width,
                $media->height
            );
            if ($panData['is_panorama'] || $panData['is_360']) {
                $media->update([
                    'is_panorama' => $panData['is_panorama'],
                    'is_360' => $panData['is_360'],
                    'panorama_projection' => $panData['panorama_projection'],
                ]);
            }

            // Live Photo / Motion Photo detection
            $liveData = $formatSvc->detectLivePhoto($rawExif ?? [], $media->extension);
            if ($liveData['is_motion_photo']) {
                $media->update([
                    'live_photo_content_id' => $liveData['content_id'],
                    'live_photo_role' => $liveData['role'],
                ]);

                // Try to link with already-uploaded pair
                if ($liveData['content_id']) {
                    $formatSvc->linkLivePhotoPair(
                        $media->id,
                        $liveData['content_id'],
                        $liveData['role'],
                        $media->gallery_space_id
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('EXIF extraction failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cancel an upload session.
     * DELETE /api/v1/uploads/{uuid}
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $session = UploadSession::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Relaci, kterou si právě převzalo dokončení, zrušit nejde: smazání
        // částí uprostřed skládání by ho shodilo („chybí část") a řádek relace
        // by zmizel dřív, než se k němu zapíše hotové médium.
        $smazano = UploadSession::whereKey($session->id)
            ->where('status', '!=', 'assembling')
            ->delete();
        if ($smazano === 0) {
            $zprava = 'Soubor se právě dokončuje — zrušit ho už nejde.';

            return response()->json(['error' => $zprava, 'message' => $zprava], 409);
        }

        Storage::disk(self::CHUNK_DISK)->deleteDirectory(self::CHUNK_DIR.'/'.$session->uuid);

        return response()->json(['status' => 'cancelled']);
    }

    private function albumForSpace(int $albumId, int $spaceId): Album
    {
        return Album::whereKey($albumId)
            ->where('gallery_space_id', $spaceId)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    /** Returns whether a new membership was created. */
    private function attachToAlbum(MediaItem $media, Album $album, int $userId): bool
    {
        return DB::transaction(function () use ($media, $album, $userId): bool {
            $alreadyAttached = DB::table('album_media')
                ->where('album_id', $album->id)
                ->where('media_item_id', $media->id)
                ->exists();

            if (! $alreadyAttached) {
                $sortOrder = (int) (DB::table('album_media')->where('album_id', $album->id)->max('sort_order') ?? -1) + 1;
                DB::table('album_media')->insert([
                    'album_id' => $album->id, 'media_item_id' => $media->id,
                    'sort_order' => $sortOrder, 'is_cover' => false,
                    'added_at' => now(), 'added_by' => $userId,
                ]);
            }

            $album->update([
                'media_count' => DB::table('album_media')->where('album_id', $album->id)->count(),
                'total_size_bytes' => MediaItem::where('primary_album_id', $album->id)->sum('size_bytes'),
            ]);

            return ! $alreadyAttached;
        });
    }

    /**
     * Přípona, kterou unese databáze i cesta na disku.
     *
     * `pathinfo` vezme všechno za poslední tečkou — z „Snímek 2024.05 dovolená
     * u moře" udělal příponu „05 dovolená u moře", která v MySQL přetekla
     * varchar(20) a nahrání spadlo na 500. Co nevypadá jako přípona, se
     * nahradí příponou podle typu souboru; když ani ten nic neřekne, zůstane prázdná.
     */
    public static function bezpecnaPripona(string $nazev, ?string $mime): string
    {
        $pripona = mb_strtolower(pathinfo($nazev, PATHINFO_EXTENSION));
        if (preg_match(self::PRIPONA, $pripona)) {
            return $pripona;
        }

        $typ = strtolower(trim(explode(';', (string) $mime)[0]));
        if ($typ === '' || $typ === 'application/octet-stream') {
            return '';
        }

        $zTypu = MimeTypes::getDefault()->getExtensions($typ)[0] ?? '';

        return preg_match(self::PRIPONA, $zTypu) ? $zTypu : '';
    }

    private function isReusableInAlbum(MediaItem $media): bool
    {
        return in_array($media->status, self::HOTOVE_STAVY, true)
            && ! $media->is_hidden
            && ! $media->trashed_at;
    }

    private function isVisibleInAlbum(MediaItem $media, Album $album): bool
    {
        if (! $this->isReusableInAlbum($media)) {
            return false;
        }

        return $media->primary_album_id === $album->id
            || DB::table('album_media')
                ->where('album_id', $album->id)
                ->where('media_item_id', $media->id)
                ->exists();
    }
}
