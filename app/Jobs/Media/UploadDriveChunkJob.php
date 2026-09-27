<?php

namespace App\Jobs\Media;

use App\Models\CloudCopyDeletion;
use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Services\Hledani\ObnovaHledani;
use App\Services\Media\KopieTrezoru;
use App\Services\Media\KopieVCloudu;
use App\Services\Storage\DriveConnectionResolver;
use App\Services\Storage\GoogleDriveStorageProvider;
use App\Support\SpaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadDriveChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $timeout = 600;

    private const MIN_CHUNK_MB = 8;

    private const MAX_CHUNK_MB = 256;

    private const CHUNK_ALIGNMENT = 256 * 1024;

    public function __construct(
        private readonly int $mediaItemId,
        private readonly ?int $uploadSessionId,
        private readonly string $driveSessionUri,
        private readonly int $startByte,
        private readonly int $totalSize,
    ) {}

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (! $media) {
            return;
        }

        if ($media->is_hidden) {
            $this->zastavVTrezoru($media);

            return;
        }

        $session = $this->uploadSessionId ? UploadSession::find($this->uploadSessionId) : null;
        $path = $session?->assembled_path;

        if (! $path || ! is_file($path)) {
            $original = $media->variants()->where('type', 'original')->first();
            $candidate = $original ? Storage::disk($original->disk)->path($original->path) : null;
            $path = $candidate && is_file($candidate) ? $candidate : null;
        }

        if (! $path || ! is_file($path)) {
            $media->update(['processing_error' => 'Zdroj pro synchronizaci do Google Drive nebyl nalezen.']);

            return;
        }

        $connection = app(DriveConnectionResolver::class)->forMedia($media);

        if (! $connection) {
            Log::warning("No storage connection for Drive chunk upload, media #{$media->id}");
            $this->release(300);

            return;
        }

        // Přes kontejner, aby šel poskytovatel v testech nahradit (jako v RemoveCloudCopy).
        $provider = app(GoogleDriveStorageProvider::class, ['connection' => $connection]);

        try {
            // First query current resumable status to get actual uploaded bytes
            $status = $provider->queryResumableStatus($this->driveSessionUri, $this->totalSize);

            if ($status['status'] === 'complete') {
                $this->finalizeMedia($media, $session, $status['file'] ?? []);

                return;
            }

            $startByte = $status['uploaded_bytes'] ?? $this->startByte;

            // Read and upload next chunk
            $handle = fopen($path, 'rb');
            fseek($handle, $startByte);
            $chunk = fread($handle, $this->chunkSize());
            fclose($handle);

            if ($chunk === false || strlen($chunk) === 0) {
                Log::warning("No data to upload at position {$startByte} for media #{$media->id}");

                return;
            }

            $chunkLen = strlen($chunk);
            $endByte = $startByte + $chunkLen - 1;

            $result = $provider->uploadChunk($this->driveSessionUri, $chunk, $startByte, $endByte, $this->totalSize);

            if ($result['status'] === 'complete') {
                $this->finalizeMedia($media, $session, $result['file'] ?? []);
            } else {
                /*
                 * Další část.
                 *
                 * Tahle třída měla vlastní `dispatch()`, které slibovalo
                 * `PendingDispatch` a vracelo samotnou úlohu — tedy `TypeError`
                 * při každém volání a nic ve frontě. Volající to má uvnitř
                 * `try`, takže se z toho stal `release()` a každý pokus založil
                 * na Googlu novou osiřelou relaci. Teď se posílají
                 * identifikátory, jak čeká zděděný `Dispatchable`.
                 */
                $session?->update(['drive_uploaded_bytes' => $endByte + 1]);
                // Známka života: podle `updated_at` se pozná zaseknuté
                // nahrávání (viz MediaItem::nahravaNaDisk) od dlouhého videa.
                $media->touch();
                static::dispatch(
                    $media->id, $session?->id, $this->driveSessionUri, $endByte + 1, $this->totalSize
                )->onQueue('drive');
            }

        } catch (\Throwable $e) {
            Log::error("Drive chunk upload failed for media #{$media->id}", [
                'error' => $e->getMessage(),
                'start_byte' => $this->startByte,
            ]);

            // Exponential backoff
            $delay = min(30 * pow(2, $this->attempts()), 3600);
            $this->release($delay);
        }
    }

    private function finalizeMedia(MediaItem $media, ?UploadSession $session, array $driveFile): void
    {
        $idNaDisku = isset($driveFile['id']) ? (string) $driveFile['id'] : null;

        if ($this->zapisDoTrezoru($media, $idNaDisku)) {
            $this->uklidDocasnySoubor($session);

            return;
        }

        $media->update([
            'drive_file_id' => $idNaDisku,
            'storage_status' => 'synced',
            'status' => 'ready',
            'processing_stage' => null,
            'processing_progress' => 100,
            'last_verified_at' => now(),
        ]);

        $this->uklidDocasnySoubor($session);

        // Hledaný text, když je všechno hotové — vazby naráz a bez dalšího
        // posunu `updated_at`, podle kterého se pozná zaseknuté nahrávání.
        app(ObnovaHledani::class)->obnovJednu($media);

        Log::info("Media #{$media->id} uploaded to Drive: {$driveFile['id']}");
    }

    /**
     * Položka odešla do trezoru uprostřed nahrávání — další části už se
     * neposílají.
     *
     * Jen se ještě zeptá Disku, jak nahrávání dopadlo: poslední část mohla na
     * Googlu doběhnout a úloha spadnout před zápisem. Takový soubor na Disku už
     * je a bez tohohle dotazu by o něm nikdo nevěděl — projde proto stejným
     * dokončením jako jindy (`finalizeMedia` → `zapisDoTrezoru`). Nedokončené
     * obnovitelné nahrávání Disk soubor nezaloží a relaci sám zahodí; stav
     * `uploading` se uvolní, aby na něj `OdeberKopieVTrezoru` nečekal.
     */
    private function zastavVTrezoru(MediaItem $media): void
    {
        $session = $this->uploadSessionId ? UploadSession::find($this->uploadSessionId) : null;
        $connection = app(DriveConnectionResolver::class)->forMedia($media);

        // Bez spojení ani dotazu nevíme, jestli soubor na Disku je — zkusit
        // znovu, ne zapomenout (stejně jako běžná cesta níž).
        if (! $connection) {
            $this->release(300);

            return;
        }

        try {
            $status = app(GoogleDriveStorageProvider::class, ['connection' => $connection])
                ->queryResumableStatus($this->driveSessionUri, $this->totalSize);
        } catch (\Throwable $e) {
            Log::warning("Drive resumable status for vault media #{$media->id} unavailable", ['error' => $e->getMessage()]);
            $this->release(min(30 * pow(2, $this->attempts()), 3600));

            return;
        }

        if (($status['status'] ?? null) === 'complete') {
            $this->finalizeMedia($media, $session, $status['file'] ?? []);

            return;
        }

        $media->update(['storage_status' => 'local_only', 'processing_stage' => null]);
    }

    /** Clean up temporary assembled file. */
    private function uklidDocasnySoubor(?UploadSession $session): void
    {
        if ($session?->assembled_path && file_exists($session->assembled_path)) {
            @unlink($session->assembled_path);
            // Try to remove empty dir
            @rmdir(dirname($session->assembled_path));
        }
    }

    /**
     * Položka odešla do trezoru během poslední části — soubor na Disku už je.
     *
     * `drive_file_id` se nezapíše a soubor se zaznamená ke smazání (`vault`),
     * stejně jako u zrcadlení do ostatních cloudů. Pod zámkem řádku, aby se
     * to nepotkalo s `OdeberKopieVTrezoru`. Vrací `true`, když šlo o trezor.
     */
    private function zapisDoTrezoru(MediaItem $media, ?string $idNaDisku): bool
    {
        $kopie = app(KopieVCloudu::class);

        $vTrezoru = (bool) MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->whereKey($media->id)->value('is_hidden');

        if (! $vTrezoru) {
            return false;
        }

        /*
         * Originál na serveru musí být ověřený dřív, než se soubor na Disku
         * zapíše ke smazání — Disk maže trvale a zdrojem nahrávání mohl být
         * dočasný sesbíraný soubor, který se hned potom uklidí. Neověřený
         * originál: `drive_file_id` se zapíše jako obvykle, kopie zůstane
         * dohledatelná a doktor ohlásí položku v trezoru s kopií v cloudu.
         * Ověřuje se mimo zámek řádku — čte celý soubor kvůli otisku.
         */
        if ($duvod = app(KopieTrezoru::class)->procNelzeOverit($media)) {
            Log::warning('Soubor na Disku doběhl u položky v trezoru, originál nejde ověřit — kopie zůstává', [
                'media_id' => $media->id,
                'duvod' => $duvod,
            ]);

            return false;
        }

        $ids = DB::transaction(function () use ($media, $idNaDisku, $kopie) {
            $ted = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->lockForUpdate()->find($media->id);

            if (! $ted?->is_hidden) {
                return null;
            }

            $ted->update(['storage_status' => 'local_only', 'processing_stage' => null]);

            return $idNaDisku
                ? [$kopie->zaznamenejJednu($ted, 'google_drive', $idNaDisku, CloudCopyDeletion::DUVOD_TREZOR)]
                : [];
        });

        if ($ids === null) {
            return false;
        }

        $kopie->zaradPoPotvrzeni($ids);
        Log::info("Media #{$media->id} je v trezoru — kopie na Disku zaznamenána ke smazání.");

        return true;
    }

    private function chunkSize(): int
    {
        $megabytes = (int) config('gallery.drive_upload_chunk_mb', 64);
        $megabytes = min(self::MAX_CHUNK_MB, max(self::MIN_CHUNK_MB, $megabytes));
        $bytes = $megabytes * 1024 * 1024;

        // Google Drive requires all non-final chunks to be 256 KB aligned.
        return intdiv($bytes, self::CHUNK_ALIGNMENT) * self::CHUNK_ALIGNMENT;
    }
}
