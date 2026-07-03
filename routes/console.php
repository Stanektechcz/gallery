<?php

use App\Console\Commands\GalleryDoctorCommand;
use App\Console\Commands\GalleryImportCommand;
use App\Console\Commands\GalleryStatusCommand;
use App\Console\Commands\RebuildAlbumsCommand;
use Illuminate\Support\Facades\Schedule;

// Scheduler tasks
Schedule::command('gallery:doctor --no-interaction')
    ->everyFiveMinutes()
    ->runInBackground()
    ->name('storage-health');

Schedule::command('queue:retry all')
    ->everyTenMinutes()
    ->name('retry-pending-drive');

Schedule::command('gallery:rebuild-albums')
    ->hourly()
    ->name('quick-reconciliation');

Schedule::command('gallery:status')
    ->dailyAt('02:00')
    ->name('daily-status');

Schedule::command('gallery:clean-temp')
    ->daily()
    ->name('temp-cleanup');

Schedule::command('gallery:scan-duplicates')
    ->weekly()
    ->name('weekly-duplicate-scan');

// Scheduler heartbeat (for doctor check)
Schedule::call(function () {
    \App\Models\SystemSetting::set('scheduler_last_heartbeat', now()->toIso8601String());
})->everyMinute()->name('scheduler-heartbeat');
