<?php

namespace App\Console\Commands;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildAlbumsCommand extends Command
{
    protected $signature   = 'gallery:rebuild-albums {--dry-run}';
    protected $description = 'Rebuild album closure table and materialized paths';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info('Rebuilding album hierarchy...');

        if (!$dryRun) {
            // Truncate closure table and rebuild
            DB::table('album_closure')->truncate();
        }

        $albums = Album::orderBy('depth')->orderBy('id')->get();

        foreach ($albums as $album) {
            if ($dryRun) {
                $this->line("  Would rebuild: {$album->title} (depth={$album->depth})");
            } else {
                $album->insertClosureRows();
                $album->rebuildPaths();
            }
        }

        if (!$dryRun) {
            $this->info('Closure table rebuilt for ' . $albums->count() . ' albums.');
        }

        return Command::SUCCESS;
    }
}
