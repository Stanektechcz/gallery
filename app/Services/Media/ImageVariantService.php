<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MediaVariant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class ImageVariantService
{
    private ImageManager $manager;

    private const VARIANTS = [
        'placeholder' => ['width' => 64,   'quality' => 40],
        'thumbnail'   => ['width' => 320,  'quality' => 80],
        'small'       => ['width' => 800,  'quality' => 82],
        'medium'      => ['width' => 1600, 'quality' => 85],
        'large'       => ['width' => 2560, 'quality' => 88],
    ];

    public function __construct()
    {
        $this->manager = new ImageManager(new GdDriver());
    }

    /**
     * Generate all standard image variants for a media item.
     */
    public function generateAll(MediaItem $mediaItem, string $sourcePath): void
    {
        foreach (self::VARIANTS as $type => $config) {
            $this->generateVariant($mediaItem, $sourcePath, $type, $config);
        }

        $this->calculateBlurHashAndColor($mediaItem, $sourcePath);
    }

    public function generateVariant(MediaItem $mediaItem, string $sourcePath, string $type, array $config): ?MediaVariant
    {
        try {
            $image = $this->manager->read($sourcePath);
            $image->scaleDown(width: $config['width']);

            $ext  = 'webp'; // prefer WebP
            // Keep every locally served file in the same directory as its
            // original. The public file proxy and upload pipeline both use
            // media/{uuid}; using a second directory here caused variants to
            // exist in the database while their URLs pointed at missing files.
            $dir  = "media/{$mediaItem->uuid}";
            $filename = "{$type}.{$ext}";
            $path = "{$dir}/{$filename}";

            $encoded = $image->toWebp($config['quality']);
            $contents = $encoded->toString();
            if (!Storage::disk('public')->put($path, $contents, 'public')) {
                throw new \RuntimeException("Variantu se nepodařilo uložit: {$path}");
            }

            return MediaVariant::updateOrCreate(
                ['media_item_id' => $mediaItem->id, 'type' => $type],
                [
                    'disk'         => 'public',
                    'path'         => $path,
                    'width'        => $image->width(),
                    'height'       => $image->height(),
                    'size_bytes'   => strlen($contents),
                    'format'       => 'webp',
                    'mime_type'    => 'image/webp',
                    'aspect_ratio' => $image->height() > 0 ? round($image->width() / $image->height(), 4) : null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error("Failed to generate {$type} variant for media #{$mediaItem->id}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function calculateBlurHashAndColor(MediaItem $mediaItem, string $sourcePath): void
    {
        try {
            $image = $this->manager->read($sourcePath);
            $image->scaleDown(width: 64); // tiny version for hash/color

            // Dominant color via simple pixel sampling
            $colors = [];
            $w = $image->width();
            $h = $image->height();
            $step = max(1, (int) ($w / 10));

            for ($x = 0; $x < $w; $x += $step) {
                for ($y = 0; $y < $h; $y += $step) {
                    $pixel = $image->pickColor($x, $y);
                    $colors[] = [$pixel->red()->value(), $pixel->green()->value(), $pixel->blue()->value()];
                }
            }

            if (!empty($colors)) {
                $avg = array_map(fn($chan) => (int) (array_sum(array_column($colors, $chan)) / count($colors)), [0, 1, 2]);
                $hex = sprintf('#%02x%02x%02x', $avg[0], $avg[1], $avg[2]);
            } else {
                $hex = '#888888';
            }

            // Update the placeholder variant with dominant color
            MediaVariant::where('media_item_id', $mediaItem->id)
                ->where('type', 'placeholder')
                ->update(['dominant_color' => $hex]);

        } catch (\Throwable $e) {
            Log::warning("Color extraction failed for media #{$mediaItem->id}", ['error' => $e->getMessage()]);
        }
    }
}
