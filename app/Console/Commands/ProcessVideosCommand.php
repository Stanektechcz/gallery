<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Media\FilenameMetadataService;
use App\Services\Media\VideoProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessVideosCommand extends Command
{
    protected $signature = 'gallery:videos
        {--all : Process every non-deleted video, not only incomplete ones}
        {--compat : Also create a web-compatible MP4 copy (slower, but improves playback on mobile)}';

    protected $description = 'Create video posters, extract technical metadata and optionally build compatible MP4 variants.';

    public function handle(VideoProcessingService $videos, FilenameMetadataService $filenames): int
    {
        if (!$videos->isAvailable()) {
            $this->error('FFmpeg nebo FFprobe není dostupný. Nastavte FFMPEG_PATH a FFPROBE_PATH nebo nainstalujte balíček ffmpeg.');
            return self::FAILURE;
        }

        $query = MediaItem::query()
            ->where('media_type', 'video')
            ->whereNull('trashed_at')
            ->with('variants');

        if (!$this->option('all')) {
            $query->where(function ($q) {
                $q->whereNull('duration_ms')
                    ->orWhereDoesntHave('variants', fn ($variants) => $variants->where('type', 'video_poster'));
            });
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('Žádná videa nevyžadují zpracování.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $done = 0;
        $failed = 0;

        $query->orderBy('id')->each(function (MediaItem $media) use ($videos, $filenames, $bar, &$done, &$failed): void {
            $original = $media->variants->firstWhere('type', 'original');
            $source = $original ? Storage::disk($original->disk)->path($original->path) : null;

            if (!$source || !is_file($source)) {
                $media->update(['processing_error' => 'Lokální originál videa nebyl nalezen.']);
                $failed++;
                $bar->advance();
                return;
            }

            try {
                $updates = $videos->extractMetadata($source);
                if (empty($updates['taken_at']) && !$media->taken_at) {
                    $updates += $filenames->infer($media->original_filename, 'video');
                }
                if (!empty($updates['taken_at']) && !$media->display_title) {
                    $date = \Carbon\Carbon::parse($updates['taken_at'])->locale('cs')->isoFormat('D. M. YYYY');
                    $updates['display_title'] = "Video z {$date}";
                }
                if ($updates) $media->update($updates);

                $videos->generatePoster($media->fresh(), $source);
                if ($this->option('compat') && !$media->variants->firstWhere('type', 'video_compat')) {
                    $videos->generateCompatibilityVariant($media->fresh(), $source);
                }
                $media->update(['processing_error' => null, 'processing_stage' => 'ready', 'processing_progress' => 100]);
                $done++;
            } catch (\Throwable $e) {
                $media->update(['processing_error' => $e->getMessage()]);
                $this->newLine();
                $this->warn("#{$media->id} {$media->original_filename}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Zpracováno: {$done}, problémů: {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
