<?php

namespace App\Console\Commands;

use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Planning\AutomationRegistryService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendRelationshipMilestoneRemindersCommand extends Command
{
    protected $signature = 'gallery:relationship-milestones {--limit=100}';
    protected $description = 'Send annual milestone and birthday reminders to the appropriate partner(s).';

    public function handle(AutomationRegistryService $automations): int
    {
        $today = today();
        $sent = 0;
        $spaces = GallerySpace::query()->get()->keyBy('id');
        $spaceIds = $spaces->filter(fn (GallerySpace $space) => $automations->enabled($space, AutomationRegistryService::RELATIONSHIP_MILESTONES))->keys()->all();
        if (! $spaceIds) { $this->info('Připomínky výročí jsou vypnuté ve všech prostorech.'); return self::SUCCESS; }
        $spaces->whereIn('id', $spaceIds)->each(fn (GallerySpace $space) => $automations->markRan($space, AutomationRegistryService::RELATIONSHIP_MILESTONES));

        $milestones = DB::table('relationship_milestones')->whereIn('gallery_space_id', $spaceIds)
            ->where('remind_annually', true)
            ->where(fn ($query) => $query->whereNull('last_reminded_on')->orWhere('last_reminded_on', '!=', $today->toDateString()))
            ->orderBy('occurred_on')->limit((int) $this->option('limit'))->get();

        foreach ($milestones as $milestone) {
            $next = Carbon::parse($milestone->occurred_on)->year($today->year)->startOfDay();
            if ($next->lt($today)) $next->addYear();
            $days = (int) $today->diffInDays($next);
            $settings = (array) ($spaces->get($milestone->gallery_space_id)?->settings ?? []);
            $relationship = (array) ($settings['relationship_anniversary'] ?? []);
            $reminderDays = (int) ($relationship['milestone_id'] ?? 0) === (int) $milestone->id
                ? array_map('intval', (array) ($relationship['reminder_days'] ?? [30, 7, 1]))
                : [7, 1, 0];
            if (!in_array($days, $reminderDays, true)) continue;

            $recipientIds = $milestone->visibility === 'private'
                ? [$milestone->created_by]
                : DB::table('gallery_space_user')->where('gallery_space_id', $milestone->gallery_space_id)->pluck('user_id')->all();
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
