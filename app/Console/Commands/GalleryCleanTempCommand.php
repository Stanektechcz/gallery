<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GalleryCleanTempCommand extends Command
{
    protected $signature   = 'gallery:clean-temp {--older-than=7 : Delete temp files older than X days}';
    protected $description = 'Clean up temporary upload files and expired export files';

    public function handle(): int
    {
        $days     = (int) $this->option('older-than');
        $cutoff   = now()->subDays($days)->timestamp;
        $cleaned  = 0;

        // Clean old upload chunks
        $chunkDir = storage_path('app/upload_chunks');
        if (is_dir($chunkDir)) {
            foreach (glob($chunkDir . '/*', GLOB_ONLYDIR) as $sessionDir) {
                if (filemtime($sessionDir) < $cutoff) {
                    array_map('unlink', glob($sessionDir . '/*'));
                    @rmdir($sessionDir);
                    $cleaned++;
                }
            }
        }

        // Clean assembled uploads that are no longer needed
        $uploadsDir = storage_path('app/uploads');
        if (is_dir($uploadsDir)) {
            foreach (glob($uploadsDir . '/*/*') as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        // Clean old exports
        $exportsDir = storage_path('app/exports');
        if (is_dir($exportsDir)) {
            foreach (glob($exportsDir . '/*.zip') as $file) {
                if (filemtime($file) < $cutoff) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        // Clean share target temp files
        $shareDir = storage_path('app/share_target');
        if (is_dir($shareDir)) {
            foreach (glob($shareDir . '/*') as $file) {
                if (is_file($file) && filemtime($file) < now()->subHours(24)->timestamp) {
                    unlink($file);
                    $cleaned++;
                }
            }
        }

        $this->info("Cleaned {$cleaned} temporary files/directories.");

        return Command::SUCCESS;
    }
}
