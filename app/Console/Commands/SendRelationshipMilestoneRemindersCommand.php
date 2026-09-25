<?php

namespace App\Console\Commands;

use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Planning\AutomationRegistryService;
use App\Support\Cas;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendRelationshipMilestoneRemindersCommand extends Command
{
    protected $signature = 'gallery:relationship-milestones {--limit=100}';

    protected $description = 'Send annual milestone and birthday reminders to the appropriate partner(s).';

    public function handle(AutomationRegistryService $automations, PristupDoGalerie $pristup): int
    {
        $today = Carbon::instance(Cas::dnes());
        $sent = 0;
        $limit = max(1, (int) $this->option('limit'));
        $spaces = GallerySpace::query()->get()->keyBy('id');
        $spaceIds = $spaces->filter(fn (GallerySpace $space) => $automations->enabled($space, AutomationRegistryService::RELATIONSHIP_MILESTONES))->keys()->all();
        if (! $spaceIds) {
            $this->info('Připomínky výročí jsou vypnuté ve všech prostorech.');

            return self::SUCCESS;
        }
        $spaces->whereIn('id', $spaceIds)->each(fn (GallerySpace $space) => $automations->markRan($space, AutomationRegistryService::RELATIONSHIP_MILESTONES));

        /*
         * `cursor()`, ne `limit($limit)->get()`.
         *
         * Podmínka „je dnes zrovna výročí" se počítá až tady v PHP (dny do
         * dalšího výročí, vlastní `reminder_days` prostoru) — do SQL nejde
         * přenést. Pevný `limit()` před tímhle výpočtem by ale ořízl frontu
         * podle `occurred_on`, ne podle toho, co je dnes due: staré záznamy,
         * které nejsou due nikdy tento den, by první stovku navždy
         * zabíraly a novější výročí by se nikdy nepřipomnělo. `--limit` teď
         * omezuje počet skutečně odeslaných připomínek, ne počet řádků,
         * které se prohlídnou.
         */
        $milestones = DB::table('relationship_milestones')->whereIn('gallery_space_id', $spaceIds)
            ->where('remind_annually', true)
            ->where(fn ($query) => $query->whereNull('last_reminded_on')->orWhere('last_reminded_on', '!=', $today->toDateString()))
            ->orderBy('occurred_on')->cursor();

        $pripomenuto = 0;

        foreach ($milestones as $milestone) {
            if ($pripomenuto >= $limit) {
                break;
            }

            $next = Carbon::parse($milestone->occurred_on)->year($today->year)->startOfDay();
            if ($next->lt($today)) {
                $next->addYear();
            }
            $days = (int) $today->diffInDays($next);
            $settings = (array) ($spaces->get($milestone->gallery_space_id)?->settings ?? []);
            $relationship = (array) ($settings['relationship_anniversary'] ?? []);
            $reminderDays = (int) ($relationship['milestone_id'] ?? 0) === (int) $milestone->id
                ? array_map('intval', (array) ($relationship['reminder_days'] ?? [30, 7, 1]))
                : [7, 1, 0];
            if (! in_array($days, $reminderDays, true)) {
                continue;
            }

            $pripomenuto++;

            // Jen dvojice — host a odebraný účet o soukromém výročí páru nemá vědět.
            $recipientIds = $milestone->visibility === 'private'
                ? [$milestone->created_by]
                : $pristup->dvojice($spaces->get($milestone->gallery_space_id))->pluck('id')->all();
            $when = $days === 0 ? 'dnes' : ($days === 1 ? 'zítra' : "za {$days} dní");
            foreach (array_unique($recipientIds) as $recipientId) {
                if ($user = User::find($recipientId)) {
                    $isBirthday = ($milestone->kind ?? 'milestone') === 'birthday';
                    $message = $isBirthday
                        ? "{$milestone->title} jsou {$when}. Je čas doladit přání nebo dárek."
                        : "Výročí „{$milestone->title}“ je {$when}.";
                    $user->notify(new GalleryNotification($isBirthday ? 'relationship.birthday' : 'relationship.milestone', $message, '/milestones', $milestone->icon ?: ($isBirthday ? '🎂' : '❤️')));
                    $sent++;
                }
            }
            DB::table('relationship_milestones')->where('id', $milestone->id)->update(['last_reminded_on' => $today->toDateString(), 'updated_at' => now()]);
        }

        $this->info("Odesláno připomínek osobních dnů: {$sent}.");

        return self::SUCCESS;
    }
}
