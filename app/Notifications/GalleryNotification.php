<?php

namespace App\Notifications;

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
        public readonly ?string $link  = null,
        public readonly ?string $icon  = null,  // emoji
        public readonly array  $extra  = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'    => $this->type,
            'message' => $this->message,
            'link'    => $this->link,
            'icon'    => $this->icon ?? $this->defaultIcon(),
            'extra'   => $this->extra,
        ];
    }

    private function defaultIcon(): string
    {
        return match ($this->type) {
            'upload.complete'  => '✅',
            'media.favorited'  => '❤️',
            'media.added'      => '📸',
            'drive.reconnect'  => '⚠️',
            'export.ready'     => '📦',
            'album.created'    => '📁',
            default            => '🔔',
        };
    }

    /**
     * Convenience: notify all members of a gallery space except the given user.
     */
    public static function notifySpace(
        \App\Models\GallerySpace $space,
        int $exceptUserId,
        string $type,
        string $message,
        ?string $link = null,
        array $extra = [],
    ): void {
        $members = $space->members()->where('users.id', '!=', $exceptUserId)->get();
        foreach ($members as $member) {
            $member->notify(new self($type, $message, $link, null, $extra));
        }
    }
}
