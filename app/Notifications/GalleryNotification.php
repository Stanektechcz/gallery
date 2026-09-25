<?php

namespace App\Notifications;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
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
        $predvolby = app(NotificationPreferenceService::class);
        $kontext = ['extra' => $this->extra, 'link' => $this->link];
        $metadata = $predvolby->metadata($this->type, $kontext);

        return [
            'type' => $this->type,
            'message' => $this->message,
            'link' => $this->link,
            'icon' => $this->icon ?? $this->defaultIcon(),
            'extra' => $this->extra,
            'category' => $metadata['category'],
            'priority' => $metadata['priority'],
            'context_key' => $metadata['context_key'],
            // Drobnost pro toho, kdo si zapnul souhrn: jen ve schránce, ohlásí ji
            // večerní souhrn (`gallery:notification-digest`), ne tahle zpráva sama.
            'digest' => $notifiable instanceof User && $predvolby->patriDoSouhrnu($notifiable, $this->type, $kontext),
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
     * `notifySpace()` níž píše celé dvojici kromě autora. Kde má zpráva jít
     * jen někomu konkrétnímu (návrh smazat fotku), rozhoduje volající, komu
     * přesně patří, a tady se jen doručí.
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
     * Upozornit dvojici prostoru kromě autora.
     *
     * Jen dvojice (`PristupDoGalerie::dvojice`), ne všichni členové: host (divák,
     * přispěvatel) by si jinak ve schránce přečetl jméno nahraného souboru, počty
     * z bankovního importu nebo import kalendáře — obsah, který mu nepatří.
     */
    public static function notifySpace(
        GallerySpace $space,
        int $exceptUserId,
        string $type,
        string $message,
        ?string $link = null,
        array $extra = [],
    ): void {
        $members = app(PristupDoGalerie::class)->dvojice($space)
            ->reject(fn (User $member) => (int) $member->id === $exceptUserId);
        foreach ($members as $member) {
            $member->notify(new self($type, $message, $link, null, $extra + [
                'gallery_space_id' => $space->id,
                'actor_user_id' => $exceptUserId,
            ]));
        }
    }
}
