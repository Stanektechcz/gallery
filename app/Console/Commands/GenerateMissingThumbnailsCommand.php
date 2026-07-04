<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateMissingThumbnailsCommand extends Command
{
    protected $signature   = 'gallery:thumbnails {--force : Regenerate even existing thumbnails} {--recover : Download from Drive if local file missing}';
    protected $description = 'Generate thumbnails for media items missing them. Use --recover to pull originals from Google Drive.';

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

        $bar       = $this->output->createProgressBar($total);
        $done      = 0;
        $fail      = 0;
        $recovered = 0;

        $query->with('variants')->each(function (MediaItem $media) use ($bar, &$done, &$fail, &$recovered) {
            if ($this->option('force')) {
                $media->variants()->where('type', 'thumbnail')->delete();
            }

            $originalVar = $media->variants()->where('type', 'original')->first();
            $sourcePath  = $originalVar ? Storage::disk($originalVar->disk)->path($originalVar->path) : null;

            if ((!$sourcePath || !file_exists($sourcePath)) && $this->option('recover') && $media->drive_file_id) {
                $sourcePath = $this->downloadFromDrive($media);
                if ($sourcePath) {
                    $recovered++;
                    $relPath = "media/{$media->uuid}/original.{$media->extension}";
                    if (Storage::disk('public')->put($relPath, fopen($sourcePath, 'rb'), 'public')) {
                        $media->variants()->updateOrCreate(['type' => 'original'], [
                            'disk' => 'public',
                            'path' => $relPath,
                            'mime_type' => $media->mime_type,
                            'size_bytes' => filesize($sourcePath),
                        ]);
                    }
                }
            }

            if (!$sourcePath || !file_exists($sourcePath)) {
                $this->newLine();
                $this->warn("  No source for #{$media->id} {$media->original_filename}" . ($media->drive_file_id ? ' (has Drive ID, use --recover)' : ' (no Drive ID)'));
                $bar->advance();
                $fail++;
                return;
            }

            try {
                $this->makeThumbnail($media, $sourcePath);
                if (!$media->taken_at && $media->media_type === 'photo') {
                    $this->extractExif($media, $sourcePath);
                }
                $done++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("  Failed #{$media->id}: {$e->getMessage()}");
                $fail++;
            }

            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Generated: {$done}, Recovered from Drive: {$recovered}, Failed/skipped: {$fail}");
        return 0;
    }

    private function downloadFromDrive(MediaItem $media): ?string
    {
        try {
            $conn = StorageConnection::whereHas(
                'owner',
                fn($q) => $q->whereHas('gallerySpaces', fn($q2) => $q2->where('gallery_spaces.id', $media->gallery_space_id))
            )->where('provider', 'google_drive')->where('connection_status', 'healthy')->first();

            if (!$conn) return null;

            $provider = new GoogleDriveStorageProvider($conn);
            $stream   = $provider->download($media->drive_file_id);
            $tmpPath  = tempnam(sys_get_temp_dir(), 'gallery_recover_') . '.' . $media->extension;
            $fh = fopen($tmpPath, 'wb');
            while (!$stream->eof()) fwrite($fh, $stream->read(65536));
            fclose($fh);
            return $tmpPath;
        } catch (\Throwable $e) {
            Log::warning('Drive download failed', ['id' => $media->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function extractExif(MediaItem $media, string $path): void
    {
        try {
            [$w, $h] = @getimagesize($path) ?: [null, null];
            $updates = [];
            if ($w) $updates['width']  = $w;
            if ($h) $updates['height'] = $h;

            // Try Imagick (supports HEIC/HEIF)
            if (extension_loaded('imagick')) {
                try {
                    $im    = new \Imagick($path . '[0]');
                    $props = $im->getImageProperties('exif:*');
                    $im->destroy();

                    if (!empty($props['exif:DateTimeOriginal'])) {
                        try {
                            $updates['taken_at'] = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $props['exif:DateTimeOriginal']);
                        } catch (\Throwable) {
                        }
                    }
                    if (!empty($props['exif:Make']))  $updates['camera_make']  = substr($props['exif:Make'],  0, 100);
                    if (!empty($props['exif:Model'])) $updates['camera_model'] = substr($props['exif:Model'], 0, 100);

                    $lat = $this->parseGps($props['exif:GPSLatitude'] ?? null, $props['exif:GPSLatitudeRef'] ?? 'N');
                    $lng = $this->parseGps($props['exif:GPSLongitude'] ?? null, $props['exif:GPSLongitudeRef'] ?? 'E');
                    if ($lat && $lng) {
                        $updates['latitude'] = $lat;
                        $updates['longitude'] = $lng;
                    }

                    if ($updates) $media->update($updates);
                    return;
                } catch (\Throwable) {
                }
            }

            // Try exiftool
            $exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');
            if (file_exists($exiftoolPath)) {
                $json = shell_exec(escapeshellcmd($exiftoolPath) . ' -json -n ' . escapeshellarg($path) . ' 2>/dev/null');
                if ($json) {
                    $data = json_decode($json, true)[0] ?? [];
                    if (!empty($data['DateTimeOriginal'])) {
                        try {
                            $updates['taken_at'] = \Carbon\Carbon::parse($data['DateTimeOriginal']);
                        } catch (\Throwable) {
                        }
                    }
                    if (!empty($data['Make']))  $updates['camera_make']  = substr($data['Make'],  0, 100);
                    if (!empty($data['Model'])) $updates['camera_model'] = substr($data['Model'], 0, 100);
                    if (!empty($data['GPSLatitude']))  $updates['latitude']  = (float) $data['GPSLatitude'];
                    if (!empty($data['GPSLongitude'])) $updates['longitude'] = (float) $data['GPSLongitude'];
                    if (!empty($data['GPSAltitude']))  $updates['altitude']  = round((float) $data['GPSAltitude'], 1);
                    if ($updates) $media->update($updates);
                    return;
                }
            }

            // Fallback: PHP exif_read_data (JPEG only)
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                if ($exif) {
                    if (!empty($exif['DateTimeOriginal'])) {
                        try {
                            $updates['taken_at'] = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
                        } catch (\Throwable) {
                        }
                    }
                    if (!empty($exif['GPSLatitude']) && !empty($exif['GPSLongitude'])) {
                        $lat = $this->gps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                        $lng = $this->gps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
                        if ($lat && $lng) {
                            $updates['latitude'] = $lat;
                            $updates['longitude'] = $lng;
                        }
                    }
                    if (!empty($exif['Make']))  $updates['camera_make']  = substr($exif['Make'],  0, 100);
                    if (!empty($exif['Model'])) $updates['camera_model'] = substr($exif['Model'], 0, 100);
                }
            }
            if ($updates) $media->update($updates);
        } catch (\Throwable) {
        }
    }

    private function parseGps(?string $raw, string $ref): ?float
    {
        if (!$raw) return null;
        $parts = array_map('trim', explode(',', $raw));
        if (count($parts) < 3) return null;
        $f = fn($v) => str_contains($v, '/') ? (float)explode('/', $v)[0] / max(1, (float)explode('/', $v)[1]) : (float)$v;
        $d = $f($parts[0]) + $f($parts[1]) / 60 + $f($parts[2]) / 3600;
        return in_array(strtoupper($ref), ['S', 'W']) ? -$d : $d;
    }

    private function gps(array $c, string $ref): ?float
    {
        if (count($c) < 3) return null;
        $f = fn($v) => is_string($v) && str_contains($v, '/') ? (float)explode('/', $v)[0] / max(1, (float)explode('/', $v)[1]) : (float)$v;
        $d = $f($c[0]) + $f($c[1]) / 60 + $f($c[2]) / 3600;
        return in_array(strtoupper($ref), ['S', 'W']) ? -$d : $d;
    }

    private function makeThumbnail(MediaItem $media, string $sourcePath): void
    {
        $thumbRel = "media/{$media->uuid}/thumbnail.jpg";
        $size     = 400;

        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick($sourcePath . '[0]');
                $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                $im->autoOrient();
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                $min = min($w, $h);
                $im->cropImage($min, $min, (int)(($w - $min) / 2), (int)(($h - $min) / 2));
                $im->thumbnailImage($size, $size);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);
                $tmp = tempnam(sys_get_temp_dir(), 'gt_') . '.jpg';
                $im->writeImage($tmp);
                $im->destroy();
                if (Storage::disk('public')->put($thumbRel, fopen($tmp, 'rb'), 'public')) {
                    @unlink($tmp);
                    $media->variants()->updateOrCreate(
                        ['type' => 'thumbnail'],
                        [
                            'disk' => 'public',
                            'path' => $thumbRel,
                            'mime_type' => 'image/jpeg',
                            'size_bytes' => Storage::disk('public')->size($thumbRel),
                            'width' => $size,
                            'height' => $size
                        ]
                    );
                    return;
                }
                @unlink($tmp);
            } catch (\Throwable $e) {
                Log::warning('Imagick thumbnail failed', ['id' => $media->id, 'e' => $e->getMessage()]);
            }
        }

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
                $w = imagesx($src);
                $h = imagesy($src);
                $min = min($w, $h);
                $thumb = imagecreatetruecolor($size, $size);
                imagecopyresampled($thumb, $src, 0, 0, (int)(($w - $min) / 2), (int)(($h - $min) / 2), $size, $size, $min, $min);
                imagedestroy($src);
                $tmp = tempnam(sys_get_temp_dir(), 'gt_') . '.jpg';
                imagejpeg($thumb, $tmp, 85);
                imagedestroy($thumb);
                if (Storage::disk('public')->put($thumbRel, fopen($tmp, 'rb'), 'public')) {
                    @unlink($tmp);
                    $media->variants()->updateOrCreate(
                        ['type' => 'thumbnail'],
                        [
                            'disk' => 'public',
                            'path' => $thumbRel,
                            'mime_type' => 'image/jpeg',
                            'size_bytes' => Storage::disk('public')->size($thumbRel),
                            'width' => $size,
                            'height' => $size
                        ]
                    );
                    return;
                }
                @unlink($tmp);
            }
        }

        $orig = $media->variants()->where('type', 'original')->first();
        if ($orig) {
            $media->variants()->updateOrCreate(
                ['type' => 'thumbnail'],
                [
                    'disk' => $orig->disk,
                    'path' => $orig->path,
                    'mime_type' => $orig->mime_type,
                    'size_bytes' => $orig->size_bytes,
                    'width' => null,
                    'height' => null
                ]
            );
        }
    }
}
