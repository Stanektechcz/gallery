<?php

use App\Console\Commands\GalleryDoctorCommand;
use App\Console\Commands\GalleryImportCommand;
use App\Console\Commands\GalleryStatusCommand;
use App\Console\Commands\RebuildAlbumsCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command('gallery:deliver-reminders --no-interaction')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('calendar-reminders');

// Every minute, because the moment's time is drawn per space per day — there is no hour
// to hang this on, which is exactly what stops anyone from being ready for it.
Schedule::command('gallery:daily-moment --no-interaction')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('daily-moment');

// Připomínka blížící se menstruace. Příkaz si sám hlídá denní dobu i to, aby za jedno
// dopoledne neposlal dvě zprávy — plánovač ho proto může volat klidně každou hodinu.
Schedule::command('gallery:cycle-reminders --no-interaction')
    ->hourly()
    ->withoutOverlapping()
    ->name('cycle-reminders');

// Večerní souhrn pro ty, kdo si ho zapnuli. Příkaz si hlídá čas i to, aby za jeden
// večer neposlal dva.
Schedule::command('gallery:notification-digest --no-interaction')
    ->hourly()
    ->withoutOverlapping()
    ->name('notification-digest');

// Automatické štítky z data a místa. V noci, protože prochází celý archiv.
Schedule::command('gallery:auto-tag --apply --no-interaction')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->name('auto-tag');

// Nevyřízené žádosti o peníze. Jednou denně stačí — a příkaz si sám hlídá, aby tutéž
// žádost nepřipomínal opakovaně.
Schedule::command('gallery:money-request-reminders --no-interaction')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->name('money-request-reminders');

// Docházející rozpočet a přetečené kategorie. Příkaz si sám hlídá denní dobu i to, aby
// tutéž zprávu neposlal dvakrát — plánovač ho proto může volat každou hodinu.
Schedule::command('gallery:budget-alerts --no-interaction')
    ->hourly()
    ->withoutOverlapping()
    ->name('budget-alerts');

// Pravidelné položky rozpočtu na nový měsíc. Prvního ráno, jinak by nájem chyběl
// v přehledu právě ve dnech, kdy se člověk dívá, jestli mu vyjde měsíc.
Schedule::command('gallery:recurring-entries --apply --no-interaction')
    ->monthlyOn(1, '06:30')
    ->withoutOverlapping()
    ->name('recurring-budget-entries');

// Old plans should never remain in current views simply because nobody opened the calendar.
Schedule::command('gallery:close-elapsed-events --no-interaction')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->name('close-elapsed-calendar-events');
Schedule::command('gallery:planning-followups --no-interaction')
    ->hourly()
    ->withoutOverlapping()
    ->name('planning-followups');

Schedule::command('gallery:relationship-milestones --no-interaction')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->name('relationship-milestones');

Schedule::command('gallery:sync-cinema --days=10 --no-interaction')
    ->dailyAt('06:15')
    ->withoutOverlapping()
    ->name('cinema-city-program');

// PSD2 providers commonly limit unattended account access to four reads per day.
Schedule::command('gallery:sync-banking --no-interaction')
    ->everySixHours()
    ->withoutOverlapping()
    ->name('read-only-bank-sync');

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
