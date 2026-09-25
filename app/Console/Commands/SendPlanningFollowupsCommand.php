<?php

namespace App\Console\Commands;

use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Planning\AutomationRegistryService;
use App\Support\Cas;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendPlanningFollowupsCommand extends Command
{
    protected $signature = 'gallery:planning-followups {--limit=100}';

    protected $description = 'Notify about approaching and overdue shared tasks and gift deadlines without duplicate alerts.';

    public function handle(AutomationRegistryService $automations): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;
        $spaces = GallerySpace::query()->get()->keyBy('id');
        $spaceIds = $spaces->filter(fn (GallerySpace $space) => $automations->enabled($space, AutomationRegistryService::PLANNING_FOLLOWUPS))->keys()->all();
        if (! $spaceIds) {
            $this->info('Připomínky plánování jsou vypnuté ve všech prostorech.');

            return self::SUCCESS;
        }
        $spaces->whereIn('id', $spaceIds)->each(fn (GallerySpace $space) => $automations->markRan($space, AutomationRegistryService::PLANNING_FOLLOWUPS));

        /*
         * „Teď" v pražských hodinách, ne v UTC.
         *
         * `due_at` se ukládá tak, jak ho člověk zadal nebo jak se odvodil ze
         * začátku akce (kalendář, jídelníček, cesta) — v pražských hodinách bez
         * pásma. Porovnání s `now()` v UTC hlásilo úkol „po termínu" až o hodinu
         * či dvě později a „brzy" naopak o tolik dřív. `last_*_at` jsou skutečné
         * okamžiky (`now()`), ty se dál porovnávají v UTC.
         */
        $tedNaHodinach = Cas::ted()->format('Y-m-d H:i:s');
        $zitraNaHodinach = Cas::ted()->addDay()->format('Y-m-d H:i:s');

        $tasks = DB::table('event_tasks as t')->join('calendar_events as e', 'e.id', '=', 't.event_id')->whereIn('e.gallery_space_id', $spaceIds)->whereNull('t.completed_at')->whereNotNull('t.due_at')->where('t.due_at', '<', $tedNaHodinach)->where(fn ($q) => $q->whereNull('t.last_escalated_at')->orWhere('t.last_escalated_at', '<', now()->subDay()))->orderBy('t.due_at')->limit($limit)->select('t.*', 'e.uuid as event_uuid', 'e.title as event_title', 'e.created_by')->get();
        foreach ($tasks as $task) {
            $recipient = User::find($task->assigned_to ?: $task->created_by);
            if (! $recipient) {
                continue;
            }
            $recipient->notify(new GalleryNotification('calendar.task.overdue', "Úkol po termínu: {$task->title} ({$task->event_title})", "/calendar/events/{$task->event_uuid}", '⚠️'));
            DB::table('event_tasks')->where('id', $task->id)->update(['last_escalated_at' => now(), 'updated_at' => now()]);
            $sent++;
        }
        $upcomingTasks = DB::table('event_tasks as t')->join('calendar_events as e', 'e.id', '=', 't.event_id')
            ->whereIn('e.gallery_space_id', $spaceIds)->whereNull('t.completed_at')->whereNotNull('t.due_at')->whereBetween('t.due_at', [$tedNaHodinach, $zitraNaHodinach])
            ->whereNull('t.last_reminded_at')->orderBy('t.due_at')->limit(max(0, $limit - $sent))
            ->select('t.*', 'e.uuid as event_uuid', 'e.title as event_title', 'e.created_by')->get();
        foreach ($upcomingTasks as $task) {
            $recipient = User::find($task->assigned_to ?: $task->created_by);
            if (! $recipient) {
                continue;
            }
            // `\v`: holé `v` je ve formátu PHP milisekundy („25. 9. 000 16:00").
            // Bez převodu pásma — termín je zapsaný podle pražských hodin, stejně
            // jako začátek akce, ze kterého se často odvozuje (viz `App\Support\Cas`).
            $when = Carbon::parse($task->due_at)->format('j. n. \v H:i');
            $recipient->notify(new GalleryNotification('calendar.task.due_soon', "Brzy je potřeba dokončit: {$task->title} ({$task->event_title}) · termín {$when}", "/calendar/events/{$task->event_uuid}", '⏰'));
            DB::table('event_tasks')->where('id', $task->id)->update(['last_reminded_at' => now(), 'updated_at' => now()]);
            $sent++;
        }
        // `cursor()`, ne `limit($limit - $sent)->get()`: jestli je dárek dnes
        // due, se pozná až tady podle jeho vlastních `reminder_days` — pevný
        // SQL limit před tím ořízl frontu podle pořadí řádků, ne podle toho,
        // co je due, a dárek za hranicí limitu se nepřipomněl nikdy.
        $gifts = DB::table('gift_ideas')->whereIn('gallery_space_id', $spaceIds)->whereNotNull('due_date')->whereNotIn('status', ['purchased', 'archived'])->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<', now()->subDay()))->orderBy('id')->cursor();
        foreach ($gifts as $gift) {
            if ($sent >= $limit) {
                break;
            }
            $days = array_map('intval', json_decode($gift->reminder_days ?: '[]', true) ?: []);
            $remaining = (int) Cas::dnes()->diffInDays(Carbon::parse($gift->due_date)->startOfDay(), false);
            if (! in_array($remaining, $days, true)) {
                continue;
            }
            $user = User::find($gift->created_by);
            if (! $user) {
                continue;
            }
            $user->notify(new GalleryNotification('gift.reminder', "Dárek „{$gift->title}“ je potřeba vyřešit za {$remaining} dní.", '/planning', '🎁'));
            DB::table('gift_ideas')->where('id', $gift->id)->update(['last_reminded_at' => now(), 'updated_at' => now()]);
            $sent++;
        }
        $this->info("Odesláno plánovacích follow-upů: {$sent}.");

        return self::SUCCESS;
    }
}
