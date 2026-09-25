<?php

namespace App\Console\Commands;

use App\Models\DailyMoment;
use App\Models\GallerySpace;
use App\Notifications\GalleryNotification;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Moments\DailyMomentService;
use App\Support\Cas;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sends the day's "Zároveň" prompt when its drawn minute arrives.
 *
 * Runs every minute and does almost nothing on nearly all of them, which is the point:
 * the time is different for every space and every day, so there is no hour to hang a
 * schedule on.
 *
 * The prompt is drawn here for spaces that have not read it yet, so somebody who never
 * opens the page still gets asked. Marking notified_at before sending means a slow
 * notification cannot cause the same prompt to go out twice.
 */
class DailyMomentCommand extends Command
{
    protected $signature = 'gallery:daily-moment
                            {--space= : Only this gallery space id}
                            {--force : Send even if the drawn time has not arrived yet}';

    protected $description = 'Rozešle dnešní výzvu Zároveň, jakmile nastane její čas.';

    public function handle(DailyMomentService $moments, PristupDoGalerie $pristup): int
    {
        // Den a okno si z tohohle okamžiku počítá `DailyMomentService` sám v
        // pásmu Praha; tady jde jen o to nebrat holé UTC.
        $now = Carbon::instance(Cas::ted());

        $spaces = GallerySpace::query()
            ->when($this->option('space'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $sent = 0;

        foreach ($spaces as $space) {
            $moment = $moments->todayFor($space, $now);

            if ($moment->notified_at) {
                continue;
            }
            if (! $this->option('force') && $moment->notify_at->isFuture()) {
                continue;
            }

            // Claimed before anything is sent. A notification that takes a while must not
            // let the next minute's run send the same prompt again.
            $claimed = DailyMoment::where('id', $moment->id)
                ->whereNull('notified_at')
                ->update(['notified_at' => $now]);

            if (! $claimed) {
                continue;
            }

            // Jen dvojice — host vidí jen sdílené odkazy a odebranému účtu se
            // o obsahu prostoru nic neposílá (viz `PristupDoGalerie::dvojice()`).
            foreach ($pristup->dvojice($space) as $member) {
                $member->notify(new GalleryNotification(
                    // Named to the "area.action" convention the rest of the app uses, which
                    // is also what files it under Galerie a vzpomínky rather than Ostatní.
                    'moment.today',
                    'Zároveň! Vyfoťte, co právě děláte — máte '.$moment->window_minutes.' minut.',
                    '/zaroven',
                    '📸',
                    ['moment_uuid' => $moment->uuid],
                ));
            }

            $sent++;
            $this->line("Prostor {$space->id}: výzva odeslána ({$moment->notify_at->format('H:i')}).");
        }

        $this->info($sent > 0 ? "Odesláno výzev: {$sent}." : 'Teď žádná výzva nezačíná.');

        return self::SUCCESS;
    }
}
