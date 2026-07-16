<?php

namespace App\Services\Planning;

use App\Models\EventReminder;
use App\Models\ReminderDeliveryLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps reminder actions personal while their calendar event stays shared.
 * Every source (trip, recipe, reservation, date or manually created event)
 * therefore uses one delivery history and one authorization rule.
 */
class ReminderActionService
{
    public function queryForUser(User $user): Builder
    {
        return EventReminder::query()->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id)
                ->orWhere(function (Builder $fallback) use ($user): void {
                    $fallback->whereNull('user_id')
                        ->whereHas('event', fn (Builder $event) => $event->where('created_by', $user->id));
                });
        });
    }

    public function findForUser(User $user, int $id, bool $lock = false): EventReminder
    {
        $query = $this->queryForUser($user)->whereKey($id)->with(['event', 'user:id,name']);
        if ($lock) $query->lockForUpdate();

        return $query->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    public function dashboard(User $user, int $spaceId, int $limit = 5): array
    {
        return $this->queryForUser($user)
            ->whereIn('status', ['pending', 'delivered', 'failed'])
            ->whereHas('event', fn (Builder $query) => $query
                ->where('gallery_space_id', $spaceId)
                ->where('starts_at', '>=', now()->subDay()))
            ->with(['event:id,uuid,gallery_space_id,created_by,title,starts_at,place_name', 'event.creator:id,name', 'user:id,name'])
            ->orderByRaw("CASE status WHEN 'delivered' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END")
            ->orderBy('remind_at')
            ->limit($limit)
            ->get()
            ->map(fn (EventReminder $reminder) => $this->payload($reminder, $user))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function payload(EventReminder $reminder, User $viewer, bool $withHistory = false): array
    {
        $reminder->loadMissing(['event:id,uuid,gallery_space_id,created_by,title,starts_at,place_name', 'event.creator:id,name', 'user:id,name']);
        $history = [];
        if ($withHistory) {
            $history = $reminder->deliveryLogs()->limit(12)->get()
                ->map(fn (ReminderDeliveryLog $log) => [
                    'status' => $log->status,
                    'channel' => $log->channel,
                    'detail' => $log->detail,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])->all();
        }

        return [
            'id' => $reminder->id,
            'event_id' => $reminder->event_id,
            'user_id' => $reminder->user_id,
            'recipient_name' => $reminder->user?->name ?? $reminder->event?->creator?->name,
            'channel' => $reminder->channel,
            'status' => $reminder->status,
            'remind_at' => $reminder->remind_at?->toIso8601String(),
            'original_remind_at' => $reminder->original_remind_at?->toIso8601String(),
            'snoozed_until' => $reminder->snoozed_until?->toIso8601String(),
            'snooze_count' => (int) $reminder->snooze_count,
            'delivered_at' => $reminder->delivered_at?->toIso8601String(),
            'acknowledged_at' => $reminder->acknowledged_at?->toIso8601String(),
            'dismissed_at' => $reminder->dismissed_at?->toIso8601String(),
            'last_error' => $reminder->last_error,
            'can_act' => (int) $reminder->user_id === (int) $viewer->id
                || ($reminder->user_id === null && (int) $reminder->event?->created_by === (int) $viewer->id),
            'event' => $reminder->event ? [
                'uuid' => $reminder->event->uuid,
                'title' => $reminder->event->title,
                'starts_at' => $reminder->event->starts_at?->toIso8601String(),
                'place_name' => $reminder->event->place_name,
            ] : null,
            'history' => $history,
        ];
    }

    public function log(EventReminder $reminder, string $status, ?string $detail = null): void
    {
        ReminderDeliveryLog::create([
            'event_reminder_id' => $reminder->id,
            'channel' => $reminder->channel,
            'status' => $status,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    public function markRelatedNotificationsRead(User $user, EventReminder $reminder): void
    {
        $user->unreadNotifications()->latest()->limit(100)->get()
            ->filter(fn ($notification) => (int) data_get($notification->data, 'extra.reminder_id') === (int) $reminder->id)
            ->each(function ($notification): void {
                $notification->markAsRead();
                if (Schema::hasColumn('notifications', 'archived_at')) {
                    $notification->forceFill(['archived_at' => now()])->save();
                }
            });
    }
}
