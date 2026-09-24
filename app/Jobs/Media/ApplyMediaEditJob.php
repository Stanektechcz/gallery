<?php

namespace App\Jobs\Media;

use App\Models\MediaEdit;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Direction;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class ApplyMediaEditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(private readonly int $mediaItemId, private readonly int $mediaEditId) {}

    /**
     * Úloha se musí opravdu odeslat do fronty.
     *
     * Tady se jen sestavila (`new static(...)->onQueue()`) a zahodila — úprava
     * z původního rozhraní se zapsala do `media_edits` a nikdy nezpracovala.
     */
    public static function dispatch(MediaItem $media, MediaEdit $edit): void
    {
        dispatch((new static($media->id, $edit->id))->onQueue('media'));
    }

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        $edit = MediaEdit::find($this->mediaEditId);
        if (! $media || ! $edit) {
            return;
        }

        // Předloha úpravy. Zmenšenina má přednost (rychlejší dekódování), ale
        // originál je v řadě taky — bez něj se u fotky bez zmenšenin úprava
        // tiše neprovedla a v prohlížeči zůstal nezměněný snímek.
        $sourceVariant = $media->getVariant('large')
            ?? $media->getVariant('medium')
            ?? $media->getVariant('original');
        if (! $sourceVariant) {
            return;
        }

        $sourcePath = Storage::disk('public')->path($sourceVariant->path);
        if (! file_exists($sourcePath)) {
            return;
        }

        // API Intervention Image 4 (`decodePath`, `encode`) — `read`/`toWebp` z verze 3 tu nejsou.
        $manager = new ImageManager(new GdDriver);
        $image = $manager->decodePath($sourcePath);

        foreach ($edit->operations_json as $op) {
            match ($op['type']) {
                'rotate' => $image->rotate($op['degrees'] ?? 90),
                'mirror_h' => $image->flip(Direction::HORIZONTAL),
                'mirror_v' => $image->flip(Direction::VERTICAL),
                'crop' => $image->crop(
                    $op['width'] ?? $image->width(),
                    $op['height'] ?? $image->height(),
                    $op['x'] ?? 0,
                    $op['y'] ?? 0
                ),
                default => null,
            };
        }

        // Save as edited_preview variant
        $path = "variants/{$media->uuid}/edited_preview.webp";
        $encoded = $image->encode(new WebpEncoder(quality: 88, strip: true));
        Storage::disk('public')->put($path, $encoded->toString());

        MediaVariant::updateOrCreate(
            ['media_item_id' => $media->id, 'type' => 'edited_preview'],
            [
                'disk' => 'public',
                'path' => $path,
                'width' => $image->width(),
                'height' => $image->height(),
                'size_bytes' => strlen($encoded->toString()),
                'format' => 'webp',
            ]
        );
    }
}
