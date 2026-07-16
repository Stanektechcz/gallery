<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventReminder;
use App\Services\Planning\ReminderActionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReminderActionController extends Controller
{
    public function __construct(private readonly ReminderActionService $reminders) {}

    public function store(Request $request, string $eventUuid): JsonResponse
    {
        $this->write($request);
        $data = $request->validate([
            'minutes_before' => ['required', 'integer', Rule::in([15, 60, 180, 1440, 10080, 43200])],
            'channel' => ['nullable', Rule::in(['database', 'email', 'push'])],
        ]);
        $user = $request->user();
        $spaceIds = $user->gallerySpaces()->pluck('gallery_spaces.id')->merge($user->ownedSpaces()->pluck('id'))->unique();
        $event = CalendarEvent::query()->where('uuid', $eventUuid)->whereIn('gallery_space_id', $spaceIds)
            ->where(fn (Builder $query) => $query->where('is_private', false)->orWhere('created_by', $user->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->whereKey($user->id)))
            ->firstOrFail();

        $minutes = (int) $data['minutes_before'];
        $remindAt = $event->starts_at->copy()->subMinutes($minutes);
        if ($remindAt->isPast()) $remindAt = now()->addMinute();
        abort_if($event->starts_at->lte(now()), 422, 'K proběhlé akci už nelze přidat novou připomínku.');

        $reminder = DB::transaction(function () use ($event, $user, $data, $minutes, $remindAt): EventReminder {
            $reminder = EventReminder::updateOrCreate([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'automation_key' => "manual-{$minutes}",
            ], [
                'channel' => $data['channel'] ?? 'database',
                'remind_at' => $remindAt,
                'original_remind_at' => $remindAt,
                'snoozed_until' => null,
                'snooze_count' => 0,
                'status' => 'pending',
                'delivered_at' => null,
                'acknowledged_at' => null,
                'dismissed_at' => null,
                'last_error' => null,
                'automation_source' => 'manual',
            ]);
            $this->reminders->log($reminder, 'scheduled', "Naplánováno {$minutes} minut před začátkem akce.");

            return $reminder;
        });

        return response()->json($this->reminders->payload($reminder->fresh(), $user, true), 201);
    }

    public function snooze(Request $request, int $reminderId): JsonResponse
    {
        $this->write($request);
        $data = $request->validate([
            'minutes' => ['required_without:until', 'nullable', 'integer', Rule::in([15, 60, 180, 1440, 10080])],
            'until' => ['required_without:minutes', 'nullable', 'date', 'after:now'],
        ]);
        $user = $request->user();

        $reminder = DB::transaction(function () use ($user, $reminderId, $data): EventReminder {
            $reminder = $this->reminders->findForUser($user, $reminderId, true);
            abort_if(in_array($reminder->status, ['acknowledged', 'dismissed'], true), 409, 'Tato připomínka už byla uzavřena.');
            abort_if((int) $reminder->snooze_count >= 20, 422, 'Připomínku už nelze dále odkládat. Zvolte vyřízeno nebo ji otevřete v kalendáři.');

            $until = isset($data['until']) ? Carbon::parse($data['until']) : now()->addMinutes((int) $data['minutes']);
            $latestUsefulTime = ($reminder->event->ends_at ?? $reminder->event->starts_at->copy()->addHours(12))->copy()->addDay();
            abort_if($until->gt($latestUsefulTime), 422, 'Zvolený čas je příliš pozdě vzhledem k termínu akce.');

            $reminder->update([
                'original_remind_at' => $reminder->original_remind_at ?? $reminder->remind_at,
                'remind_at' => $until,
                'snoozed_until' => $until,
                'snooze_count' => (int) $reminder->snooze_count + 1,
                'status' => 'pending',
                'delivered_at' => null,
                'acknowledged_at' => null,
                'dismissed_at' => null,
                'last_error' => null,
            ]);
            $this->reminders->log($reminder, 'snoozed', 'Odloženo do '.$until->locale('cs')->translatedFormat('j. n. Y H:i').'.');

            return $reminder;
        });

        $this->reminders->markRelatedNotificationsRead($user, $reminder);

        return response()->json($this->reminders->payload($reminder->fresh(), $user, true));
    }

    public function acknowledge(Request $request, int $reminderId): JsonResponse
    {
        return $this->finish($request, $reminderId, 'acknowledged');
    }

    public function dismiss(Request $request, int $reminderId): JsonResponse
    {
        return $this->finish($request, $reminderId, 'dismissed');
    }

    private function finish(Request $request, int $reminderId, string $status): JsonResponse
    {
        $this->write($request);
        $user = $request->user();
        $reminder = DB::transaction(function () use ($user, $reminderId, $status): EventReminder {
            $reminder = $this->reminders->findForUser($user, $reminderId, true);
            if ($reminder->status === $status) return $reminder;
            abort_if(in_array($reminder->status, ['acknowledged', 'dismissed'], true), 409, 'Tato připomínka už byla uzavřena.');
            $now = now();
            $reminder->update([
                'status' => $status,
                'acknowledged_at' => $status === 'acknowledged' ? $now : null,
                'dismissed_at' => $status === 'dismissed' ? $now : null,
                'snoozed_until' => null,
                'last_error' => null,
            ]);
            $this->reminders->log($reminder, $status, $status === 'acknowledged' ? 'Uživatel označil připomínku jako vyřízenou.' : 'Uživatel připomínku skryl.');

            return $reminder;
        });
        $this->reminders->markRelatedNotificationsRead($user, $reminder);

        return response()->json($this->reminders->payload($reminder->fresh(), $user, true));
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze připomínky měnit.');
    }
}
