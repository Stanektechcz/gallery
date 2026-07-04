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
        $done = 0;
        $fail = 0;
        $nogps = 0;

        $exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');

        $query->with('variants')->each(function (MediaItem $media) use ($bar, &$done, &$fail, &$nogps, $exiftoolPath) {
            // Find local source file
            $originalVar = $media->variants()->where('type', 'original')->first();
            $sourcePath  = $originalVar ? Storage::disk($originalVar->disk)->path($originalVar->path) : null;

            if (!$sourcePath || !file_exists($sourcePath)) {
                $this->newLine();
                $this->line("  <comment>No local file for #{$media->id} {$media->original_filename}</comment>");
                $bar->advance();
                $fail++;
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
        return (new \App\Services\ExifExtractorService())->extract($sourcePath);
    }
}
