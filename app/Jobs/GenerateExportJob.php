<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 3600;

    public function __construct(
        private readonly int    $userId,
        private readonly array  $options,
        private readonly string $jobId,
    ) {}

    public function handle(): void
    {
        Cache::put("export_status_{$this->jobId}", 'processing', 3600);

        try {
            $user = \App\Models\User::find($this->userId);
            if (!$user) return;

            $media = match ($this->options['type']) {
                'album' => \App\Models\MediaItem::where('primary_album_id', $this->options['target_id'])->get(),
                'selection' => \App\Models\MediaItem::whereIn('id', $this->options['media_ids'] ?? [])->get(),
                default     => collect(),
            };

            $zipPath = storage_path("app/exports/{$this->jobId}.zip");
            @mkdir(dirname($zipPath), 0755, true);

            $zip = new \ZipArchive();
            $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            foreach ($media as $item) {
                // Add the largest available local variant
                $variant = $item->getVariant('large') ?? $item->getVariant('medium');
                if ($variant) {
                    $variantPath = Storage::disk('public')->path($variant->path);
                    if (file_exists($variantPath)) {
                        $zip->addFile($variantPath, $item->original_filename);
                    }
                }
            }

            $zip->close();

            Cache::put("export_status_{$this->jobId}", 'ready', 3600);
        } catch (\Throwable $e) {
            Cache::put("export_status_{$this->jobId}", 'failed', 3600);
            throw $e;
        }
    }
}
