<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Models\MediaVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateMissingThumbnailsCommand extends Command
{
    protected $signature   = 'gallery:thumbnails {--force : Regenerate even if thumbnail exists}';
    protected $description = 'Generate thumbnails for media items that are missing them';

    public function handle(): int
    {
        $query = MediaItem::whereDoesntHave('variants', fn($q) => $q->where('type', 'thumbnail'));

        if ($this->option('force')) {
            $query = MediaItem::query();
        }

        $total = $query->count();
        $this->info("Found {$total} media items without thumbnails.");

        if ($total === 0) {
            $this->info('Nothing to do.');
            return 0;
        }

        $bar  = $this->output->createProgressBar($total);
        $done = 0;
        $fail = 0;

        $query->each(function (MediaItem $media) use ($bar, &$done, &$fail) {
            $originalVar = $media->variants()->where('type', 'original')->first();

            if (!$originalVar) {
                $bar->advance();
                $fail++;
                return;
            }

            $sourcePath = Storage::disk($originalVar->disk)->path($originalVar->path);

            if (!file_exists($sourcePath)) {
                $this->newLine();
                $this->warn("Missing source file for media #{$media->id}: {$sourcePath}");
                $bar->advance();
                $fail++;
                return;
            }

            try {
                if ($this->option('force')) {
                    $media->variants()->where('type', 'thumbnail')->delete();
                }

                $this->makeThumbnail($media, $sourcePath);
                $done++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Failed media #{$media->id}: {$e->getMessage()}");
                $fail++;
            }

            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Generated: {$done}, Failed/skipped: {$fail}");

        return 0;
    }

    private function makeThumbnail(MediaItem $media, string $sourcePath): void
    {
        $thumbRel = "media/{$media->uuid}/thumbnail.jpg";
        $size     = 400;

        // Try Imagick
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick($sourcePath);
                $im->setIteratorIndex(0);
                $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                $im->autoOrient();

                $w   = $im->getImageWidth();
                $h   = $im->getImageHeight();
                $min = min($w, $h);
                $im->cropImage($min, $min, (int)(($w - $min) / 2), (int)(($h - $min) / 2));
                $im->thumbnailImage($size, $size);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);

                $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_thumb_') . '.jpg';
                $im->writeImage($tmpPath);
                $im->destroy();

                if (Storage::disk('public')->put($thumbRel, fopen($tmpPath, 'rb'))) {
                    @unlink($tmpPath);
                    $media->variants()->updateOrCreate(['type' => 'thumbnail'], [
                        'disk'       => 'public',
                        'path'       => $thumbRel,
                        'mime_type'  => 'image/jpeg',
                        'size_bytes' => Storage::disk('public')->size($thumbRel),
                        'width'      => $size,
                        'height'     => $size,
                    ]);
                    return;
                }
                @unlink($tmpPath);
            } catch (\Throwable $e) {
                Log::warning('Imagick thumbnail failed in command', ['id' => $media->id, 'error' => $e->getMessage()]);
            }
        }

        // Try GD
        if (extension_loaded('gd')) {
            $ext = strtolower($media->extension);
            $src = match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
                'png'         => @imagecreatefrompng($sourcePath),
                'webp'        => @imagecreatefromwebp($sourcePath),
                'gif'         => @imagecreatefromgif($sourcePath),
                default       => null,
            };

            if ($src) {
                $origW = imagesx($src);
                $origH = imagesy($src);
                $min   = min($origW, $origH);

                $thumb = imagecreatetruecolor($size, $size);
                imagecopyresampled($thumb, $src, 0, 0, (int)(($origW - $min) / 2), (int)(($origH - $min) / 2), $size, $size, $min, $min);
                imagedestroy($src);

                $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_thumb_') . '.jpg';
                imagejpeg($thumb, $tmpPath, 85);
                imagedestroy($thumb);

                if (Storage::disk('public')->put($thumbRel, fopen($tmpPath, 'rb'))) {
                    @unlink($tmpPath);
                    $media->variants()->updateOrCreate(['type' => 'thumbnail'], [
                        'disk'       => 'public',
                        'path'       => $thumbRel,
                        'mime_type'  => 'image/jpeg',
                        'size_bytes' => Storage::disk('public')->size($thumbRel),
                        'width'      => $size,
                        'height'     => $size,
                    ]);
                    return;
                }
                @unlink($tmpPath);
            }
        }

        // Fallback: alias to original
        $originalVar = $media->variants()->where('type', 'original')->first();
        if ($originalVar) {
            $media->variants()->updateOrCreate(['type' => 'thumbnail'], [
                'disk'       => $originalVar->disk,
                'path'       => $originalVar->path,
                'mime_type'  => $originalVar->mime_type,
                'size_bytes' => $originalVar->size_bytes,
                'width'      => null,
                'height'     => null,
            ]);
        }
    }
}
