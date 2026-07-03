<?php

namespace App\Jobs\Media;

use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Models\MediaItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateMediaHashesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $assembled = storage_path("app/uploads/{$media->uploaded_by}_*/{$media->original_filename}");

        // Find the assembled file path via upload session
        $session = \App\Models\UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        if (!$path || !file_exists($path)) {
            Log::warning("Assembled file not found for media #{$media->id}");
            $media->update(['status' => 'failed', 'processing_error' => 'Assembled file not found']);
            return;
        }

        $media->update([
            'status'          => 'extracting_metadata',
            'processing_stage'=> 'hashing',
            'sha256'          => hash_file('sha256', $path),
            'md5'             => hash_file('md5', $path),
        ]);

        // Dispatch next job in pipeline
        ExtractMediaMetadataJob::dispatch($media)->onQueue('media');
    }
}
