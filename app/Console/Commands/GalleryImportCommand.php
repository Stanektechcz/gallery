<?php

namespace App\Console\Commands;

use App\Jobs\Media\CalculateMediaHashesJob;
use App\Models\Album;
use App\Models\MediaItem;
use App\Models\UploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GalleryImportCommand extends Command
{
    protected $signature = 'gallery:import
        {path : Local filesystem path to import}
        {--album= : Target album UUID}
        {--recursive : Process subdirectories as nested albums}
        {--preserve-tree : Preserve directory structure as album hierarchy}
        {--dry-run : Show what would be imported without doing it}
        {--duplicate-policy=skip : How to handle duplicates (skip|replace|keep-both)}
        {--user= : User ID to attribute uploads to (defaults to owner)}';

    protected $description = 'Import media files from a local directory';

    private array $stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!is_dir($path) && !is_file($path)) {
            $this->error("Path not found: {$path}");
            return Command::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN — no files will actually be imported');
        }

        $user = \App\Models\User::find($this->option('user'))
            ?? \App\Models\User::where('role', 'owner')->first();

        if (!$user) {
            $this->error('No owner user found. Create users first.');
            return Command::FAILURE;
        }

        $space = $user->gallerySpaces()->first();
        if (!$space) {
            $this->error('No gallery space found for user.');
            return Command::FAILURE;
        }

        $targetAlbum = null;
        if ($albumUuid = $this->option('album')) {
            $targetAlbum = Album::where('uuid', $albumUuid)->first();
            if (!$targetAlbum) {
                $this->error("Album not found: {$albumUuid}");
                return Command::FAILURE;
            }
        }

        if (is_file($path)) {
            $this->importFile($path, $user, $space, $targetAlbum, $dryRun);
        } else {
            $this->importDirectory($path, $user, $space, $targetAlbum, $dryRun, null);
        }

        $this->info("Import complete: {$this->stats['imported']} imported, {$this->stats['skipped']} skipped, {$this->stats['errors']} errors");

        return Command::SUCCESS;
    }

    private function importDirectory(string $dir, $user, $space, ?Album $album, bool $dryRun, ?Album $parentAlbum): void
    {
        if ($this->option('preserve-tree')) {
            $folderName = basename($dir);
            if ($dryRun) {
                $this->line("  [DIR] Would create album: {$folderName}");
            } else {
                $albumService = new \App\Services\AlbumService();
                $album = $albumService->create($space, [
                    'title'     => $folderName,
                    'parent_id' => $parentAlbum?->id,
                ], $user);
                \App\Jobs\Drive\CreateDriveFolderJob::dispatch($album);
                $this->line("  [ALBUM] Created: {$folderName}");
            }
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath) && $this->option('recursive')) {
                $this->importDirectory($fullPath, $user, $space, $album, $dryRun, $album);
            } elseif (is_file($fullPath)) {
                $this->importFile($fullPath, $user, $space, $album, $dryRun);
            }
        }
    }

    private function importFile(string $path, $user, $space, ?Album $album, bool $dryRun): void
    {
        $ext       = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'heic', 'heif', 'tiff', 'tif', 'mp4', 'mov', 'webm', 'm4v', 'mkv'];

        if (!in_array($ext, $allowed)) {
            $this->line("  [SKIP] Unsupported: {$path}");
            $this->stats['skipped']++;
            return;
        }

        $sha256 = hash_file('sha256', $path);

        // Check for duplicates
        $duplicate = MediaItem::where('gallery_space_id', $space->id)->where('sha256', $sha256)->first();
        if ($duplicate && $this->option('duplicate-policy') === 'skip') {
            $this->line("  [SKIP] Duplicate: {$path}");
            $this->stats['skipped']++;
            return;
        }

        if ($dryRun) {
            $this->line("  [IMPORT] Would import: {$path}");
            $this->stats['imported']++;
            return;
        }

        try {
            $mime      = mime_content_type($path) ?: 'application/octet-stream';
            $filename  = basename($path);
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'photo';
            $destPath  = storage_path("app/imports/{$sha256}/" . $filename);
            @mkdir(dirname($destPath), 0755, true);
            copy($path, $destPath);

            $media = MediaItem::create([
                'gallery_space_id'  => $space->id,
                'owner_user_id'     => $user->id,
                'uploaded_by'       => $user->id,
                'primary_album_id'  => $album?->id,
                'original_filename' => $filename,
                'safe_filename'     => preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename),
                'extension'         => $ext,
                'mime_type'         => $mime,
                'media_type'        => $mediaType,
                'size_bytes'        => filesize($path),
                'sha256'            => $sha256,
                'status'            => 'received',
                'imported_at'       => now(),
                'uploaded_at'       => now(),
            ]);

            // Create a fake upload session for the pipeline
            $uploadSession = UploadSession::create([
                'user_id'           => $user->id,
                'gallery_space_id'  => $space->id,
                'target_album_id'   => $album?->id,
                'original_filename' => $filename,
                'mime_type'         => $mime,
                'total_size'        => filesize($path),
                'total_chunks'      => 1,
                'received_chunks'   => 1,
                'sha256'            => $sha256,
                'status'            => 'completed',
                'assembled_path'    => $destPath,
                'expires_at'        => now()->addDays(7),
                'completed_at'      => now(),
                'resulting_media_id' => $media->id,
            ]);

            CalculateMediaHashesJob::dispatch($media)->onQueue('media');

            $this->line("  [OK] Imported: {$filename}");
            $this->stats['imported']++;
        } catch (\Throwable $e) {
            $this->error("  [ERROR] {$path}: {$e->getMessage()}");
            $this->stats['errors']++;
        }
    }
}
