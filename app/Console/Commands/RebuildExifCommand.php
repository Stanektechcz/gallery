<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RebuildExifCommand extends Command
{
    protected $signature   = 'gallery:exif {--all : Re-extract even items that already have GPS} {--clean-orphans : Delete media records with no local file and no Drive ID}';
    protected $description = 'Re-extract EXIF (GPS, date, camera) from local files using Imagick/exiftool';

    public function handle(): int
    {
        if ($this->option('clean-orphans')) {
            $this->cleanOrphans();
        }

        $query = MediaItem::where('media_type', 'photo')
            ->whereNull('trashed_at');

        if (!$this->option('all')) {
            // Only items missing GPS
            $query->whereNull('latitude');
        }

        $total = $query->count();
        $this->info("Found {$total} photo items to process.");

        if ($total === 0) {
            $this->info('Nothing to do. Use --all to re-extract from all photos.');
            return 0;
        }

        $bar  = $this->output->createProgressBar($total);
        $done = 0; $fail = 0; $nogps = 0;

        $exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');

        $query->with('variants')->each(function (MediaItem $media) use ($bar, &$done, &$fail, &$nogps, $exiftoolPath) {
            // Find local source file
            $originalVar = $media->variants()->where('type', 'original')->first();
            $sourcePath  = $originalVar ? Storage::disk($originalVar->disk)->path($originalVar->path) : null;

            if (!$sourcePath || !file_exists($sourcePath)) {
                $this->newLine();
                $this->line("  <comment>No local file for #{$media->id} {$media->original_filename}</comment>");
                $bar->advance(); $fail++;
                return;
            }

            $updates = $this->extractExif($sourcePath, $exiftoolPath);

            if ($updates) {
                $media->update($updates);
                if (isset($updates['latitude'])) {
                    $done++;
                    $this->newLine();
                    $this->line("  <info>#{$media->id} GPS: {$updates['latitude']}, {$updates['longitude']}</info>");
                } else {
                    $nogps++;
                }
            } else {
                $nogps++;
            }

            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. With GPS: {$done}, No GPS in file: {$nogps}, Failed: {$fail}");
        return 0;
    }

    private function cleanOrphans(): void
    {
        $orphans = MediaItem::whereNull('drive_file_id')
            ->whereDoesntHave('variants')
            ->orWhere(function ($q) {
                $q->whereNull('drive_file_id')
                  ->whereHas('variants', function ($q2) {
                      $q2->where('type', 'original');
                  }, '=', 0);
            });

        // More precise: find items where the original file doesn't exist and no drive_file_id
        $cleaned = 0;
        MediaItem::whereNull('drive_file_id')->with('variants')->each(function (MediaItem $media) use (&$cleaned) {
            $originalVar = $media->variants()->where('type', 'original')->first();
            $hasLocal = $originalVar && file_exists(Storage::disk($originalVar->disk ?? 'public')->path($originalVar->path));

            if (!$hasLocal) {
                $this->line("  Removing orphan: #{$media->id} {$media->original_filename}");
                $media->variants()->delete();
                $media->forceDelete();
                $cleaned++;
            }
        });

        $this->info("Cleaned {$cleaned} orphaned media records.");
    }

    private function extractExif(string $sourcePath, string $exiftoolPath): array
    {
        $updates = [];

        // 1. Try exiftool (best - handles HEIC, JPEG, RAW, everything)
        if (file_exists($exiftoolPath)) {
            $json = shell_exec(escapeshellcmd($exiftoolPath) . ' -json -n -charset UTF8 ' . escapeshellarg($sourcePath) . ' 2>/dev/null');
            if ($json) {
                $data = json_decode($json, true)[0] ?? [];
                if (!empty($data['DateTimeOriginal'])) {
                    try { $updates['taken_at'] = \Carbon\Carbon::parse($data['DateTimeOriginal']); } catch (\Throwable) {}
                }
                if (!empty($data['Make']))  $updates['camera_make']  = substr($data['Make'],  0, 100);
                if (!empty($data['Model'])) $updates['camera_model'] = substr($data['Model'], 0, 100);
                if (!empty($data['GPSLatitude']))  $updates['latitude']  = (float) $data['GPSLatitude'];
                if (!empty($data['GPSLongitude'])) $updates['longitude'] = (float) $data['GPSLongitude'];
                if (!empty($data['GPSAltitude']))  $updates['altitude']  = round((float) $data['GPSAltitude'], 1);
                if (!empty($data['FocalLength']))  $updates['focal_length']  = (string) $data['FocalLength'];
                if (!empty($data['ISO']))           $updates['iso']           = (int) $data['ISO'];
                if (!empty($data['Aperture']))      $updates['aperture']      = (string) $data['Aperture'];
                if (!empty($data['ExposureTime']))  $updates['shutter_speed'] = (string) $data['ExposureTime'];
                if (!empty($data['ImageWidth']))    $updates['width']         = (int) $data['ImageWidth'];
                if (!empty($data['ImageHeight']))   $updates['height']        = (int) $data['ImageHeight'];
                if (!empty($data['LensModel']))     $updates['lens_model']    = substr($data['LensModel'], 0, 255);
                return $updates;
            }
        }

        // 2. Imagick fallback
        if (extension_loaded('imagick')) {
            try {
                $im    = new \Imagick($sourcePath . '[0]');
                $props = $im->getImageProperties('exif:*');
                $im->destroy();

                if (!empty($props['exif:DateTimeOriginal'])) {
                    try { $updates['taken_at'] = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $props['exif:DateTimeOriginal']); } catch (\Throwable) {}
                }
                if (!empty($props['exif:Make']))  $updates['camera_make']  = substr($props['exif:Make'],  0, 100);
                if (!empty($props['exif:Model'])) $updates['camera_model'] = substr($props['exif:Model'], 0, 100);

                $lat = $this->parseGps($props['exif:GPSLatitude'] ?? null, $props['exif:GPSLatitudeRef'] ?? 'N');
                $lng = $this->parseGps($props['exif:GPSLongitude'] ?? null, $props['exif:GPSLongitudeRef'] ?? 'E');
                if ($lat && $lng) { $updates['latitude'] = $lat; $updates['longitude'] = $lng; }
            } catch (\Throwable) {}
        }

        return $updates;
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
}
