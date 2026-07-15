<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\GalleryNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendPlanningFollowupsCommand extends Command
{
    protected $signature = 'gallery:planning-followups {--limit=100}';
    protected $description = 'Notify about approaching and overdue shared tasks and gift deadlines without duplicate alerts.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit')); $sent = 0;
        $tasks = DB::table('event_tasks as t')->join('calendar_events as e', 'e.id', '=', 't.event_id')->whereNull('t.completed_at')->whereNotNull('t.due_at')->where('t.due_at', '<', now())->where(fn ($q) => $q->whereNull('t.last_escalated_at')->orWhere('t.last_escalated_at', '<', now()->subDay()))->orderBy('t.due_at')->limit($limit)->select('t.*', 'e.uuid as event_uuid', 'e.title as event_title', 'e.created_by')->get();
        foreach ($tasks as $task) {
            $recipient = User::find($task->assigned_to ?: $task->created_by);
            if (!$recipient) continue;
            $recipient->notify(new GalleryNotification('calendar.task.overdue', "Úkol po termínu: {$task->title} ({$task->event_title})", "/calendar/events/{$task->event_uuid}", '⚠️'));
            DB::table('event_tasks')->where('id', $task->id)->update(['last_escalated_at' => now(), 'updated_at' => now()]); $sent++;
        }
        $upcomingTasks = DB::table('event_tasks as t')->join('calendar_events as e', 'e.id', '=', 't.event_id')
            ->whereNull('t.completed_at')->whereNotNull('t.due_at')->whereBetween('t.due_at', [now(), now()->copy()->addDay()])
            ->whereNull('t.last_reminded_at')->orderBy('t.due_at')->limit(max(0, $limit - $sent))
            ->select('t.*', 'e.uuid as event_uuid', 'e.title as event_title', 'e.created_by')->get();
        foreach ($upcomingTasks as $task) {
            $recipient = User::find($task->assigned_to ?: $task->created_by);
            if (! $recipient) continue;
            $when = \Carbon\Carbon::parse($task->due_at)->locale('cs')->translatedFormat('j. n. v H:i');
            $recipient->notify(new GalleryNotification('calendar.task.due_soon', "Brzy je potřeba dokončit: {$task->title} ({$task->event_title}) · termín {$when}", "/calendar/events/{$task->event_uuid}", '⏰'));
            DB::table('event_tasks')->where('id', $task->id)->update(['last_reminded_at' => now(), 'updated_at' => now()]); $sent++;
        }
        $gifts = DB::table('gift_ideas')->whereNotNull('due_date')->whereNotIn('status', ['purchased', 'archived'])->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<', now()->subDay()))->limit(max(0, $limit - $sent))->get();
        foreach ($gifts as $gift) {
            $days = array_map('intval', json_decode($gift->reminder_days ?: '[]', true) ?: []); $remaining = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($gift->due_date)->startOfDay(), false);
            if (!in_array($remaining, $days, true)) continue;
            $user = User::find($gift->created_by); if (!$user) continue;
            $user->notify(new GalleryNotification('gift.reminder', "Dárek „{$gift->title}“ je potřeba vyřešit za {$remaining} dní.", '/planning', '🎁'));
            DB::table('gift_ideas')->where('id', $gift->id)->update(['last_reminded_at' => now(), 'updated_at' => now()]); $sent++;
        }
        $this->info("Odesláno plánovacích follow-upů: {$sent}.");
        return self::SUCCESS;
    }
}
