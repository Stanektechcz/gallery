<?php

namespace App\Jobs\Media;

use App\Models\Album;
use App\Models\MediaItem;
use App\Services\Media\AlbumCurationAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnqueueAlbumDriveSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(private readonly int $albumId) {}

    public function handle(AlbumCurationAssistantService $assistant): void
    {
        $album = Album::find($this->albumId);
        if (! $album) {
            return;
        }

        $assistant->mediaQuery($album)
            ->whereNull('drive_file_id')
            ->select('id')
            ->lazyById(100)
            ->each(fn (MediaItem $media) => InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive'));
    }
}
