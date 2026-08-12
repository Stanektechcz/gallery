<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\SpaceSubscription;
use App\Notifications\GalleryNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Tells people before their subscription lapses, not after.
 *
 * Three moments worth a word: a trial about to end, a paid period about to end, and one
 * that already has. Nothing else — a billing system that writes often is one people learn
 * to ignore, and then it cannot tell them the thing that mattered.
 *
 * Sent to the owner alone. Everybody in a space shares the plan, but only one person can
 * do anything about it, and telling a partner their subscription is expiring when they
 * cannot pay for it is worry without a remedy.
 */
class BillingRemindersCommand extends Command
{
    protected $signature = 'gallery:billing-reminders {--dry-run : Jen vypsat, nic neodesílat}';

    protected $description = 'Upozorní na blížící se konec zkušebního období a předplatného';

    /** Days before the end at which each kind of warning goes out. */
    private const TRIAL_WARNING = 3;
    private const RENEWAL_WARNING = 7;

    public function handle(): int
    {
        if (! Schema::hasTable('space_subscriptions')) {
            $this->warn('Předplatná zatím nejsou v databázi.');

            return self::SUCCESS;
        }

        $sent = 0;

        $subscriptions = SpaceSubscription::with('plan')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('ends_at')
            ->get();

        foreach ($subscriptions as $subscription) {
            $notice = $this->noticeFor($subscription);
            if (! $notice) continue;

            $space = GallerySpace::find($subscription->gallery_space_id);
            $owner = $space?->members()->where('users.role', 'owner')->first()
                ?? $space?->members()->first();

            if (! $owner) continue;

            // One of each kind per subscription period. Without this the command would say
            // the same thing every day it runs, which is how a warning becomes wallpaper.
            $already = AuditLog::where('action', 'billing.reminder')
                ->where('subject_type', 'GallerySpace')
                ->where('subject_id', $space->id)
                ->where('created_at', '>=', $subscription->started_at ?? now()->subYear())
                ->get()
                ->contains(fn ($row) => ($row->payload['kind'] ?? null) === $notice['kind']);

            if ($already) continue;

            $this->line("Prostor {$space->id}: {$notice['kind']} — {$notice['message']}");

            if ($this->option('dry-run')) continue;

            Notification::send($owner, new GalleryNotification(
                type: 'billing.reminder',
                message: $notice['message'],
                link: '/settings/predplatne',
                icon: $notice['icon'],
            ));

            AuditLog::record('billing.reminder', $space, ['kind' => $notice['kind']]);
            $sent++;
        }

        $this->info($this->option('dry-run') ? 'Nic neodesláno (dry-run).' : "Odesláno: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Czech counts days in three forms, and a message reading "za 2 dní" is the sort of
     * thing that makes a product feel translated rather than written.
     */
    private static function inDays(int $days): string
    {
        if ($days <= 0) return 'dnes';
        if ($days === 1) return 'zítra';

        return 'za ' . $days . ($days <= 4 ? ' dny' : ' dní');
    }

    /**
     * What, if anything, this subscription's owner should hear about today.
     *
     * Expiry is checked before the warnings, so a subscription that lapsed while nobody was
     * looking is reported as lapsed rather than as "ending soon".
     *
     * @return array{kind: string, message: string, icon: string}|null
     */
    private function noticeFor(SpaceSubscription $subscription): ?array
    {
        $endsAt = $subscription->ends_at;
        if (! $endsAt) return null;

        $plan = $subscription->plan?->name ?? 'tarif';

        if ($endsAt->isPast()) {
            return [
                'kind' => 'expired',
                'icon' => '⚠️',
                'message' => $subscription->status === 'trialing'
                    ? "Zkušební období skončilo. Galerie běží dál na základním tarifu."
                    : "Předplatné {$plan} skončilo. Galerie běží dál na základním tarifu.",
            ];
        }

        $days = (int) ceil(now()->diffInDays($endsAt, false));

        if ($subscription->status === 'trialing' && $days <= self::TRIAL_WARNING) {
            return [
                'kind' => 'trial_ending',
                'icon' => '⏳',
                'message' => 'Zkušební období končí ' . self::inDays($days) . '. Bez předplatného se galerie vrátí na základní tarif.',
            ];
        }

        if ($subscription->status === 'active' && $days <= self::RENEWAL_WARNING) {
            return [
                'kind' => 'renewal',
                'icon' => '📅',
                'message' => "Předplatné {$plan} končí " . self::inDays($days) . '.',
            ];
        }

        return null;
    }
}
