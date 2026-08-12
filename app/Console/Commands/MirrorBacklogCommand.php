<?php

namespace App\Console\Commands;

use App\Jobs\MirrorMediaToCloud;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Storage\StorageResolver;
use App\Support\SpaceContext;
use Illuminate\Console\Command;

/**
 * Copies the photographs that were already there when a cloud was connected.
 *
 * Connecting a Dropbox mirrors everything uploaded afterwards, which leaves the library
 * somebody actually cares about sitting only on our disk. This walks the backlog.
 *
 * Queued rather than uploaded here. A library is thousands of files; doing it in one
 * process means one network hiccup loses the lot, while jobs retry individually and the
 * work survives the command being interrupted.
 */
class MirrorBacklogCommand extends Command
{
    protected $signature = 'gallery:mirror-backlog
        {--space= : Jen jeden prostor podle id}
        {--limit=500 : Kolik nejvýš zařadit najednou}
        {--dry-run : Jen spočítat, nic nezařazovat}';

    protected $description = 'Zařadí do fronty kopie fotek, které v cloudu ještě nejsou';

    public function handle(StorageResolver $resolver): int
    {
        $spaces = GallerySpace::when($this->option('space'), fn ($query, $id) => $query->whereKey($id))->get();
        $limit = max(1, (int) $this->option('limit'));
        $queued = 0;

        foreach ($spaces as $space) {
            $connection = $resolver->activeConnection($space);

            if (! $connection) {
                $this->line("Prostor {$space->id}: bez cloudu, přeskočeno.");
                continue;
            }

            // Only what is finished and still here. Media still processing has no original
            // to copy, and something in the bin is not a thing to push into somebody's
            // Dropbox on their behalf.
            $pending = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $space->id)
                ->where('status', 'ready')
                ->whereNull('trashed_at')
                ->whereDoesntHave('variants', fn ($query) => $query->where('disk', $connection->provider))
                ->whereHas('variants', fn ($query) => $query->where('type', 'original'))
                ->limit($limit)
                ->pluck('id');

            $this->line("Prostor {$space->id} ({$connection->provider}): ke kopírování {$pending->count()}");

            if ($this->option('dry-run')) continue;

            foreach ($pending as $id) {
                MirrorMediaToCloud::dispatch($id);
                $queued++;
            }
        }

        $this->info($this->option('dry-run') ? 'Nic nezařazeno (dry-run).' : "Zařazeno do fronty: {$queued}");

        // Reported so a scheduler can call this repeatedly and see when there is nothing
        // left, rather than guessing from a log line.
        return self::SUCCESS;
    }
}
