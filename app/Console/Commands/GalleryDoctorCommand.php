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

        // Pending migrations.
        //
        // Counted rather than inferred from migrate:status, which succeeds whether or not
        // anything is pending — so this reported PASS unconditionally and would have said
        // the database was fine on the day a half-finished deploy left three migrations
        // unrun and every upload failing on a missing column.
        try {
            $spustene = DB::table('migrations')->pluck('migration')->all();

            $cekaji = collect(glob(database_path('migrations/*.php')))
                ->map(fn (string $cesta) => basename($cesta, '.php'))
                ->reject(fn (string $jmeno) => in_array($jmeno, $spustene, true))
                ->values();

            $this->check(
                $cekaji->isEmpty()
                    ? 'No pending migrations'
                    : "Pending migrations: {$cekaji->count()} ({$cekaji->take(3)->implode(', ')}" . ($cekaji->count() > 3 ? ', …' : '') . ')',
                $cekaji->isEmpty(),
            );
        } catch (\Throwable $e) {
            $this->check('Pending migrations: could not be read (' . $e->getMessage() . ')', false);
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

        $this->checkImageFormats();
    }

    /**
     * Which picture formats this server can actually turn into a thumbnail.
     *
     * Worth asking out loud, because the failure is silent and looks like something
     * else: without the HEIC delegate every photograph straight off an iPhone uploads
     * fine, keeps its bytes, and then shows as a broken image in the grid. The customer
     * reports that "photos do not upload", which is not what happened at all.
     */
    private function checkImageFormats(): void
    {
        $this->section('Image Formats');

        $imagick = extension_loaded('imagick');
        $this->check('imagick extension', $imagick, 'WARN');
        $this->check('gd extension', extension_loaded('gd'), 'WARN');

        if (! $imagick) {
            $this->check('HEIC/HEIF thumbnails (iPhone photos)', false, 'WARN');

            return;
        }

        $formats = array_map('strtoupper', \Imagick::queryFormats());

        foreach (['HEIC' => 'HEIC/HEIF thumbnails (iPhone photos)', 'AVIF' => 'AVIF thumbnails', 'TIFF' => 'TIFF thumbnails'] as $format => $label) {
            $this->check($label, in_array($format, $formats, true), 'WARN');
        }
    }

    private function checkQueue(): void
    {
        $this->section('Queue');
        $driver = config('queue.default');

        // "sync" is not a queue at all: every job runs inside the web request that
        // created it. On an upload that means variant generation, EXIF extraction and
        // the copy to the space's cloud all happen before the browser gets an answer —
        // which is how a large video ends up timing out instead of uploading.
        //
        // Reported as PASS whatever the driver was, which said nothing to anybody.
        if ($driver === 'sync') {
            $this->check(
                'Queue driver: sync — jobs run inside web requests, uploads will stall on large files',
                false,
                app()->environment('production') ? 'FAIL' : 'WARN',
            );
        } else {
            $this->check("Queue driver: {$driver}", true);
        }

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

        if (! $heartbeat) {
            // Never written once. Cron is not calling schedule:run at all, which means
            // no reminders, no Zároveň prompt, no nightly cleanup — none of it, silently.
            $this->check('Scheduler has never run — cron is not calling schedule:run', false);

            return;
        }

        // abs(), because Carbon returns a signed difference and a heartbeat in the past
        // gives a negative one: a scheduler dead for three hours read as -180 and passed
        // the "< 5" test, so this only ever complained when the value was missing entirely.
        $stari = (int) abs(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($heartbeat)));

        $this->check(
            $stari < 5
                ? "Scheduler heartbeat recent ({$stari} min)"
                : "Scheduler last ran {$stari} min ago — reminders and daily prompts are not going out",
            $stari < 5,
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
        $maRefresh = ! empty($connection->getRefreshToken());
        $this->check('Refresh token present', $maRefresh);

        // An access token expiring is ordinary — Google issues them by the hour — and the
        // provider fetches a new one before its next call. Reporting that as a red FAIL
        // sent somebody looking for a fault on a connection that was working perfectly.
        // It only matters when there is no refresh token to recover with.
        if (! $connection->isTokenExpired()) {
            $this->check('Access token valid', true);
        } else {
            $this->check(
                $maRefresh
                    ? 'Access token expired — will be renewed on next use (normal)'
                    : 'Access token expired and no refresh token — reconnect required',
                $maRefresh,
            );
        }

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
