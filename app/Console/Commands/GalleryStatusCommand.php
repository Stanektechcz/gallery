<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\UploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GalleryStatusCommand extends Command
{
    protected $signature   = 'gallery:status';
    protected $description = 'Show current gallery system status';

    public function handle(): int
    {
        $this->info('Stanektech Gallery — Status');
        $this->line('');

        // Storage
        $conn = StorageConnection::where('provider', 'google_drive')->first();
        $this->info('Storage Connection:');
        $this->table(['Key', 'Value'], [
            ['Status',        $conn?->connection_status ?? 'not configured'],
            ['Account',       $conn?->account_email ?? '—'],
            ['Root Folder',   $conn?->root_folder_name ?? '—'],
            ['Quota Used',    $conn ? $this->formatBytes($conn->quota_used ?? 0) : '—'],
            ['Quota Total',   $conn ? $this->formatBytes($conn->quota_total ?? 0) : '—'],
            ['Last OK',       $conn?->last_successful_request_at?->diffForHumans() ?? '—'],
        ]);

        // Media stats
        $this->info('Media Statistics:');
        $this->table(['Metric', 'Count'], [
            ['Total Media',    MediaItem::count()],
            ['Photos',         MediaItem::where('media_type', 'photo')->count()],
            ['Videos',         MediaItem::where('media_type', 'video')->count()],
            ['Ready',          MediaItem::where('status', 'ready')->count()],
            ['Processing',     MediaItem::whereNotIn('status', ['ready', 'failed'])->count()],
            ['Failed',         MediaItem::where('status', 'failed')->count()],
            ['In Trash',       MediaItem::whereNotNull('trashed_at')->count()],
            ['Archived',       MediaItem::where('is_archived', true)->count()],
        ]);

        // Albums
        $this->info('Albums:');
        $this->table(['Metric', 'Count'], [
            ['Total Albums',   Album::count()],
            ['Root Albums',    Album::whereNull('parent_id')->count()],
            ['Max Depth',      Album::max('depth') ?? 0],
            ['Pending Sync',   Album::where('sync_status', 'pending')->count()],
            ['Failed Sync',    Album::where('sync_status', 'failed')->count()],
        ]);

        // Queue
        $this->info('Queue:');
        $pending = DB::table('jobs')->count();
        $failed  = DB::table('failed_jobs')->count();
        $uploading = UploadSession::where('status', 'pending')->count();
        $this->table(['Metric', 'Count'], [
            ['Pending Jobs',    $pending],
            ['Failed Jobs',     $failed],
            ['Active Uploads',  $uploading],
        ]);

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
