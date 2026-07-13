<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\Tag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ExtractXmpMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int   $mediaItemId,
        private readonly array $keywords,
    ) {}

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $space = $media->gallerySpace;

        foreach ($this->keywords as $keyword) {
            $slug = Str::slug($keyword);
            $tag  = Tag::firstOrCreate(
                ['gallery_space_id' => $space->id, 'slug' => $slug],
                [
                    'gallery_space_id' => $space->id,
                    'name'             => $keyword,
                    'slug'             => $slug,
                    'depth'            => 0,
                    'materialized_path' => '',
                ]
            );
            $media->tags()->syncWithoutDetaching([$tag->id]);
        }

        $media->rebuildSearchText();
    }
}
