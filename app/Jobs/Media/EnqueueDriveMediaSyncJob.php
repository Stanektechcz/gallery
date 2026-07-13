<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Queue every unsynchronised original in one shared gallery space. */
class EnqueueDriveMediaSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(private readonly int $gallerySpaceId) {}

    public function handle(): void
    {
        MediaItem::query()
            ->where('gallery_space_id', $this->gallerySpaceId)
            ->whereNull('trashed_at')
            ->whereNull('drive_file_id')
            ->orderBy('id')
            ->select('id')
            ->lazyById(100)
            ->each(fn (MediaItem $media) => InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive'));
    }
}
