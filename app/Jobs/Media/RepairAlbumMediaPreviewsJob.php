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

class RepairAlbumMediaPreviewsJob implements ShouldQueue
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
            ->whereDoesntHave('variants', fn ($variants) => $variants->whereIn('type', ['thumbnail', 'small', 'video_poster']))
            ->select(['id', 'media_type'])
            ->lazyById(100)
            ->each(function (MediaItem $media): void {
                $job = $media->media_type === 'video'
                    ? GenerateVideoPosterJob::dispatch($media->id)
                    : GenerateImageVariantsJob::dispatch($media->id);
                $job->onQueue('media');
            });
    }
}
