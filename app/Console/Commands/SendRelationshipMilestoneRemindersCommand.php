<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\GalleryNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendRelationshipMilestoneRemindersCommand extends Command
{
    protected $signature = 'gallery:relationship-milestones {--limit=100}';
    protected $description = 'Send one useful annual anniversary reminder to the appropriate partner(s).';

    public function handle(): int
    {
        $today = today();
        $sent = 0;
        $milestones = DB::table('relationship_milestones')
            ->where('remind_annually', true)
            ->where(fn ($query) => $query->whereNull('last_reminded_on')->orWhere('last_reminded_on', '!=', $today->toDateString()))
            ->orderBy('occurred_on')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($milestones as $milestone) {
            $next = Carbon::parse($milestone->occurred_on)->year($today->year)->startOfDay();
            if ($next->lt($today)) $next->addYear();
            $days = (int) $today->diffInDays($next);
            if (!in_array($days, [0, 1, 7], true)) continue;

            $recipientIds = $milestone->visibility === 'private'
                ? [$milestone->created_by]
                : DB::table('gallery_space_user')->where('gallery_space_id', $milestone->gallery_space_id)->pluck('user_id')->all();
            $when = $days === 0 ? 'dnes' : ($days === 1 ? 'zítra' : 'za 7 dní');
            foreach (array_unique($recipientIds) as $recipientId) {
                if ($user = User::find($recipientId)) {
                    $user->notify(new GalleryNotification('relationship.milestone', "Výročí „{$milestone->title}“ je {$when}.", '/milestones', $milestone->icon ?: '❤️'));
                    $sent++;
                }
            }
            DB::table('relationship_milestones')->where('id', $milestone->id)->update(['last_reminded_on' => $today->toDateString(), 'updated_at' => now()]);
        }

        $this->info("Odesláno připomínek výročí: {$sent}.");
        return self::SUCCESS;
    }
}
