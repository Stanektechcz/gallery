<?php

namespace App\Console\Commands;

use App\Models\StorageConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\OutputInterface;

class GalleryDoctorCommand extends Command
{
    protected $signature   = 'gallery:doctor {--fix : Attempt automatic fixes}';
    protected $description = 'Run a comprehensive system health check';

    private array $results = [];

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════╗');
        $this->info('║      Stanektech Gallery — Doctor     ║');
        $this->info('╚══════════════════════════════════════╝');
        $this->info('');

        $this->checkLaravel();
        $this->checkDatabase();
        $this->checkStorage();
        $this->checkPhp();
        $this->checkBinaries();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkGoogleDrive();

        // Summary
        $passed  = count(array_filter($this->results, fn($r) => $r['status'] === 'PASS'));
        $warned  = count(array_filter($this->results, fn($r) => $r['status'] === 'WARN'));
        $failed  = count(array_filter($this->results, fn($r) => $r['status'] === 'FAIL'));

        $this->info('');
        $this->info("Results: {$passed} PASS  {$warned} WARN  {$failed} FAIL");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function checkLaravel(): void
    {
        $this->section('Laravel');
        $this->check('APP_KEY set',    !empty(config('app.key')));
        $this->check('APP_DEBUG=false (prod)', config('app.debug') === false, 'WARN');
        $this->check('APP_URL set',    !empty(config('app.url')));
        $this->check('APP_ENV=production', config('app.env') === 'production', 'WARN');
    }

    private function checkDatabase(): void
    {
        $this->section('Database');
        try {
            DB::connection()->getPdo();
            $this->check('DB connection', true);
        } catch (\Throwable $e) {
            $this->check('DB connection', false);
            return;
        }

        // Pending migrations
        try {
            \Artisan::call('migrate:status', ['--no-interaction' => true]);
            $this->check('No pending migrations', true);
        } catch (\Throwable $e) {
            $this->check('No pending migrations', false);
        }

        // Charset check (MySQL only)
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            try {
                $charset = DB::select("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()")[0]->DEFAULT_CHARACTER_SET_NAME ?? '';
                $this->check("DB charset is utf8mb4", $charset === 'utf8mb4', 'WARN');
            } catch (\Throwable) {
                $this->check("DB charset is utf8mb4", false, 'WARN');
            }
        } else {
            $this->check("DB driver: {$driver}", true);
        }
    }

    private function checkStorage(): void
    {
        $this->section('Local Storage');

        $disks = [
            'temp writes'    => storage_path('app'),
            'cache writes'   => storage_path('framework/cache'),
            'thumbnail dir'  => storage_path('app/public/variants'),
        ];

        foreach ($disks as $label => $dir) {
            @mkdir($dir, 0755, true);
            $writable = is_writable($dir);
            $this->check($label, $writable);
        }

        $freeMb = disk_free_space(storage_path()) / 1024 / 1024;
        $this->check("Free space >= 1 GB", $freeMb >= 1024, $freeMb >= 512 ? 'WARN' : 'FAIL');
    }

    private function checkPhp(): void
    {
        $this->section('PHP');
        $version = PHP_VERSION;
        $this->check("PHP >= 8.3 (running {$version})", version_compare($version, '8.3.0', '>='));

        $extensions = ['gd', 'exif', 'fileinfo', 'mbstring', 'curl', 'intl', 'zip', 'bcmath'];
        foreach ($extensions as $ext) {
            $this->check("ext-{$ext}", extension_loaded($ext));
        }

        $memLimit = (int) ini_get('memory_limit');
        $this->check("memory_limit >= 256M ({$memLimit}M)", $memLimit >= 256 || $memLimit === -1, 'WARN');
    }

    private function checkBinaries(): void
    {
        $this->section('External Binaries');

        $binaries = [
            'ffmpeg'   => config('gallery.ffmpeg_path', '/usr/bin/ffmpeg'),
            'ffprobe'  => config('gallery.ffprobe_path', '/usr/bin/ffprobe'),
            'exiftool' => config('gallery.exiftool_path', '/usr/bin/exiftool'),
        ];

        foreach ($binaries as $name => $path) {
            $exists = file_exists($path) && is_executable($path);
            $this->check("{$name} at {$path}", $exists, 'WARN');
        }
    }

    private function checkQueue(): void
    {
        $this->section('Queue');
        $driver = config('queue.default');
        $this->check("Queue driver: {$driver}", true);

        if ($driver === 'database') {
            try {
                $pending = DB::table('jobs')->count();
                $failed  = DB::table('failed_jobs')->count();
                $this->check("Pending jobs: {$pending}", true);
                $this->check("Failed jobs: {$failed}", $failed === 0, $failed < 10 ? 'WARN' : 'FAIL');
            } catch (\Throwable) {
                $this->check('jobs table accessible', false);
            }
        }
    }

    private function checkScheduler(): void
    {
        $this->section('Scheduler');
        $heartbeat = \App\Models\SystemSetting::get('scheduler_last_heartbeat');
        $this->check(
            'Scheduler heartbeat recent',
            $heartbeat && now()->diffInMinutes($heartbeat) < 5,
            'WARN'
        );
    }

    private function checkGoogleDrive(): void
    {
        $this->section('Google Drive');
        $this->check('CLIENT_ID configured',     !empty(config('services.google.client_id')));
        $this->check('CLIENT_SECRET configured', !empty(config('services.google.client_secret')));

        $connection = StorageConnection::where('provider', 'google_drive')
            ->where('connection_status', 'healthy')
            ->first();

        if (!$connection) {
            $this->check('OAuth connection active', false);
            return;
        }

        $this->check('OAuth connection active', true);
        $this->check('Account: ' . ($connection->account_email ?? 'unknown'), true);
        $this->check('Root folder configured', !empty($connection->root_folder_id));
        $this->check('Refresh token present', !empty($connection->getRefreshToken()));
        $this->check('Token not expired', !$connection->isTokenExpired());

        $lastOk = $connection->last_successful_request_at;
        $this->check(
            'Last successful request < 24h',
            $lastOk && $lastOk->diffInHours(now()) < 24,
            'WARN'
        );
    }

    private function section(string $title): void
    {
        $this->info('');
        $this->line("  <fg=cyan;options=bold>── {$title}</>");
    }

    private function check(string $label, bool $pass, string $failLevel = 'FAIL'): void
    {
        $status = $pass ? 'PASS' : $failLevel;
        $color  = match ($status) {
            'PASS' => 'green',
            'WARN' => 'yellow',
            'FAIL' => 'red',
        };

        $icon = match ($status) {
            'PASS' => '✓',
            'WARN' => '⚠',
            'FAIL' => '✗',
        };

        $this->line("    <fg={$color}>{$icon} {$status}</> {$label}");
        $this->results[] = ['label' => $label, 'status' => $status];
    }
}
