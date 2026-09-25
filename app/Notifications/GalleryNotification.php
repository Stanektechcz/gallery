<?php

namespace App\Notifications;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Notifications\Notification;

/**
 * GalleryNotification — universal in-app notification via database channel.
 * Used for: uploads, favorites, new photos, Drive warnings, export ready.
 */
class GalleryNotification extends Notification
{
    public function __construct(
        public readonly string $type,    // upload.complete | media.favorited | media.added | drive.reconnect | export.ready
        public readonly string $message,
        public readonly ?string $link = null,
        public readonly ?string $icon = null,  // emoji
        public readonly array $extra = [],
    ) {}

    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User
            && ! app(NotificationPreferenceService::class)->allows($notifiable, $this->type, ['extra' => $this->extra, 'link' => $this->link])) {
            return [];
        }

        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $metadata = app(NotificationPreferenceService::class)->metadata($this->type, ['extra' => $this->extra, 'link' => $this->link]);

        return [
            'type' => $this->type,
            'message' => $this->message,
            'link' => $this->link,
            'icon' => $this->icon ?? $this->defaultIcon(),
            'extra' => $this->extra,
            'category' => $metadata['category'],
            'priority' => $metadata['priority'],
            'context_key' => $metadata['context_key'],
        ];
    }

    private function defaultIcon(): string
    {
        return match ($this->type) {
            'upload.complete' => '✅',
            'media.favorited' => '❤️',
            'media.added' => '📸',
            'media.trash_proposed' => '🗑️',
            'drive.reconnect' => '⚠️',
            'export.ready' => '📦',
            'album.created' => '📁',
            'calendar.task.assigned', 'todo.assigned' => '✅',
            'calendar.task.overdue' => '⚠️',
            'memory.capsule' => '💌',
            'relationship.birthday' => '🎂',
            'relationship.milestone' => '❤️',
            'gift.reminder' => '🎁',
            'finance.imported', 'bank.synced' => '💳',
            default => '🔔',
        };
    }

    /**
     * Upozornit jen vyjmenované účty.
     *
     * `notifySpace()` níž píše všem členům prostoru — i hostům. U věcí, které
     * patří jen dvojici (návrh smazat fotku), rozhoduje volající, komu přesně
     * zpráva patří, a tady se jen doručí.
     *
     * @param  iterable<int>  $userIds
     */
    public static function notifyUsers(
        GallerySpace $space,
        int $actorUserId,
        iterable $userIds,
        string $type,
        string $message,
        ?string $link = null,
        array $extra = [],
    ): void {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->reject(fn (int $id) => $id === $actorUserId)->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            $user->notify(new self($type, $message, $link, null, $extra + [
                'gallery_space_id' => $space->id,
                'actor_user_id' => $actorUserId,
            ]));
        }
    }

    /**
     * Convenience: notify all members of a gallery space except the given user.
     */
    public static function notifySpace(
        GallerySpace $space,
        int $exceptUserId,
        string $type,
        string $message,
        ?string $link = null,
        array $extra = [],
    ): void {
        $members = $space->members()->where('users.id', '!=', $exceptUserId)->get();
        foreach ($members as $member) {
            $member->notify(new self($type, $message, $link, null, $extra + [
                'gallery_space_id' => $space->id,
                'actor_user_id' => $exceptUserId,
            ]));
        }
    }
}
