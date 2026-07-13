<?php

namespace App\Console\Commands;

use App\Jobs\Media\EnqueueDriveMediaSyncJob;
use App\Models\GallerySpace;
use App\Services\Storage\DriveConnectionResolver;
use Illuminate\Console\Command;

class SyncDriveMediaCommand extends Command
{
    protected $signature = 'gallery:sync-drive {--all : Queue every gallery space with a healthy Google Drive} {--space= : Queue one gallery space by ID}';
    protected $description = 'Queue unsynchronised media for resumable Google Drive backup.';

    public function handle(DriveConnectionResolver $connections): int
    {
        $spaces = GallerySpace::query()->when($this->option('space'), fn ($query, $id) => $query->whereKey($id));
        if (!$this->option('all') && !$this->option('space')) {
            $this->error('Použijte --all nebo --space=ID.');
            return self::INVALID;
        }

        $queued = 0;
        $spaces->select('id')->orderBy('id')->each(function (GallerySpace $space) use ($connections, &$queued): void {
            if (!$connections->forSpace($space->id)) {
                $this->warn("Pro prostor #{$space->id} není dostupný zdravý Google Drive.");
                return;
            }
            EnqueueDriveMediaSyncJob::dispatch($space->id)->onQueue('drive');
            $queued++;
        });

        $this->info("Synchronizace zařazena pro {$queued} prostorů.");
        return self::SUCCESS;
    }
}
