<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class PurgeMediaFromDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(private readonly int $mediaItemId, private readonly ?string $driveFileId = null) {}

    public static function dispatch(MediaItem $media): void
    {
        // Úloha se musí do fronty opravdu odeslat — samotné `new static(...)->onQueue()`
        // ji jen sestavilo a zahodilo, takže změna na Google Disku nikdy neproběhla.
        Bus::dispatch((new static($media->id, $media->drive_file_id))->onQueue('drive'));
    }

    public function handle(): void
    {
        if (! $this->driveFileId) {
            return;
        }

        $connection = StorageConnection::where('provider', 'google_drive')
            ->where('connection_status', 'healthy')
            ->first();

        if (! $connection) {
            $this->release(300);

            return;
        }

        $provider = new GoogleDriveStorageProvider($connection);
        $provider->trash($this->driveFileId);
    }
}
