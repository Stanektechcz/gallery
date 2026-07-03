<?php

namespace App\Jobs\Media;

use App\Models\MediaEdit;
use App\Models\MediaItem;
use App\Services\Media\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class ApplyMediaEditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(private readonly int $mediaItemId, private readonly int $mediaEditId) {}

    public static function dispatch(MediaItem $media, MediaEdit $edit): void
    {
        (new static($media->id, $edit->id))->onQueue('media');
    }

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        $edit  = MediaEdit::find($this->mediaEditId);
        if (!$media || !$edit) return;

        // Find the local large or medium variant to use as source
        $sourceVariant = $media->getVariant('large') ?? $media->getVariant('medium');
        if (!$sourceVariant) return;

        $sourcePath = Storage::disk('public')->path($sourceVariant->path);
        if (!file_exists($sourcePath)) return;

        $manager = new ImageManager(new GdDriver());
        $image   = $manager->read($sourcePath);

        foreach ($edit->operations_json as $op) {
            match ($op['type']) {
                'rotate'   => $image->rotate($op['degrees'] ?? 90),
                'mirror_h' => $image->flip('h'),
                'mirror_v' => $image->flip('v'),
                'crop'     => $image->crop(
                    $op['width'] ?? $image->width(),
                    $op['height'] ?? $image->height(),
                    $op['x'] ?? 0,
                    $op['y'] ?? 0
                ),
                default    => null,
            };
        }

        // Save as edited_preview variant
        $path    = "variants/{$media->uuid}/edited_preview.webp";
        $encoded = $image->toWebp(88);
        Storage::disk('public')->put($path, $encoded->toString());

        \App\Models\MediaVariant::updateOrCreate(
            ['media_item_id' => $media->id, 'type' => 'edited_preview'],
            [
                'disk'       => 'public',
                'path'       => $path,
                'width'      => $image->width(),
                'height'     => $image->height(),
                'size_bytes' => strlen($encoded->toString()),
                'format'     => 'webp',
            ]
        );
    }
}
