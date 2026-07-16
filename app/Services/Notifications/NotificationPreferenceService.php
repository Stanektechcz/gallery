<?php

namespace App\Services\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;

class NotificationPreferenceService
{
    public const CATEGORIES = [
        'planning' => 'Plány a úkoly',
        'travel' => 'Cesty a doprava',
        'memories' => 'Galerie a vzpomínky',
        'relationship' => 'Vztah a výročí',
        'finance' => 'Společné finance',
        'system' => 'Systém a zabezpečení',
        'general' => 'Ostatní',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /** @return array<string, mixed> */
    public function preferences(User $user): array
    {
        $saved = (array) data_get($user->preferences, 'notifications', []);
        $categories = array_replace(array_fill_keys(array_keys(self::CATEGORIES), true), (array) ($saved['categories'] ?? []));
        $categories['system'] = true;
        $priorityFloor = (string) ($saved['priority_floor'] ?? 'low');
        $legacyQuiet = (array) data_get($user->preferences, 'quiet_hours', []);
        $quiet = array_key_exists('quiet', $saved) ? (array) $saved['quiet'] : [
            'enabled' => $legacyQuiet !== [],
            'from' => $legacyQuiet['from'] ?? '22:00',
            'to' => $legacyQuiet['to'] ?? '07:00',
        ];

        return [
            'categories' => collect($categories)->map(fn ($value) => (bool) $value)->all(),
            'priority_floor' => in_array($priorityFloor, self::PRIORITIES, true) ? $priorityFloor : 'low',
            'quiet' => [
                'enabled' => (bool) ($quiet['enabled'] ?? false),
                'from' => $this->validTime((string) ($quiet['from'] ?? '22:00'), '22:00'),
                'to' => $this->validTime((string) ($quiet['to'] ?? '07:00'), '07:00'),
            ],
            'browser_notifications' => (bool) ($saved['browser_notifications'] ?? false),
        ];
    }

    /** @param array<string, mixed> $validated */
    public function update(User $user, array $validated): array
    {
        $current = $this->preferences($user);
        $next = array_replace_recursive($current, $validated);
        $next['categories'] = collect(array_keys(self::CATEGORIES))->mapWithKeys(
            fn (string $category) => [$category => (bool) ($next['categories'][$category] ?? true)]
        )->all();
        $next['categories']['system'] = true;
        $next['priority_floor'] = in_array($next['priority_floor'], self::PRIORITIES, true) ? $next['priority_floor'] : 'low';
        $preferences = $user->preferences ?? [];
        $preferences['notifications'] = $next;
        $preferences['quiet_hours'] = $next['quiet']['enabled']
            ? ['from' => $next['quiet']['from'], 'to' => $next['quiet']['to']]
            : null;
        $user->update(['preferences' => $preferences]);

        return $next;
    }

    public function categoryForType(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'calendar.'), str_starts_with($type, 'todo.'), str_starts_with($type, 'gift.') => 'planning',
            str_starts_with($type, 'trip.'), str_starts_with($type, 'ticket.'), str_starts_with($type, 'transport.'), str_starts_with($type, 'place.'), str_starts_with($type, 'reservation.') => 'travel',
            str_starts_with($type, 'memory.'), str_starts_with($type, 'media.'), str_starts_with($type, 'album.'), str_starts_with($type, 'upload.'), str_starts_with($type, 'date_idea.') => 'memories',
            str_starts_with($type, 'relationship.'), str_starts_with($type, 'anniversary.'), str_starts_with($type, 'birthday.') => 'relationship',
            str_starts_with($type, 'bank.'), str_starts_with($type, 'finance.'), str_starts_with($type, 'expense.'), str_starts_with($type, 'settlement.') => 'finance',
            str_starts_with($type, 'drive.'), str_starts_with($type, 'security.'), str_starts_with($type, 'system.'), str_starts_with($type, 'export.') => 'system',
            default => 'general',
        };
    }

    public function priorityForType(string $type): string
    {
        return match (true) {
            str_contains($type, 'security'), str_contains($type, 'failed'), str_contains($type, 'emergency') => 'critical',
            str_contains($type, 'overdue'), str_contains($type, 'reconnect'), str_contains($type, 'reminder'), str_contains($type, 'assigned'), str_contains($type, 'capsule') => 'high',
            str_contains($type, 'favorited'), str_contains($type, 'response'), str_contains($type, 'comment') => 'normal',
            str_contains($type, 'complete'), str_contains($type, 'added') => 'low',
            default => 'normal',
        };
    }

    /** @param array<string, mixed> $data */
    public function metadata(string $type, array $data = []): array
    {
        $extra = (array) ($data['extra'] ?? $data);
        $category = (string) ($data['category'] ?? $extra['category'] ?? $this->categoryForType($type));
        if (! isset(self::CATEGORIES[$category])) $category = 'general';
        $priority = (string) ($data['priority'] ?? $extra['priority'] ?? $this->priorityForType($type));
        if (! in_array($priority, self::PRIORITIES, true)) $priority = 'normal';

        return [
            'category' => $category,
            'category_label' => self::CATEGORIES[$category],
            'priority' => $priority,
            'context_key' => $data['context_key'] ?? $extra['context_key'] ?? $this->contextKey($extra, $data['link'] ?? null),
        ];
    }

    public function allows(User $user, string $type, array $data = []): bool
    {
        $meta = $this->metadata($type, $data);
        if ($type === 'calendar.reminder' && data_get($data, 'extra.reminder_id')) return true;
        if ($meta['priority'] === 'critical') return true;
        $preferences = $this->preferences($user);

        return ($preferences['categories'][$meta['category']] ?? true)
            && $this->rank($meta['priority']) >= $this->rank($preferences['priority_floor']);
    }

    public function isQuiet(User $user, ?CarbonInterface $at = null): bool
    {
        $quiet = $this->preferences($user)['quiet'];
        if (! $quiet['enabled']) return false;
        $time = ($at ?? now())->timezone(config('app.timezone'))->format('H:i');
        if ($quiet['from'] === $quiet['to']) return true;

        return $quiet['from'] < $quiet['to']
            ? $time >= $quiet['from'] && $time < $quiet['to']
            : $time >= $quiet['from'] || $time < $quiet['to'];
    }

    public function rank(string $priority): int
    {
        $rank = array_search($priority, self::PRIORITIES, true);
        return $rank === false ? 1 : $rank;
    }

    /** @param array<string, mixed> $extra */
    private function contextKey(array $extra, ?string $link): ?string
    {
        foreach (['event_uuid' => 'event', 'todo_uuid' => 'todo', 'trip_uuid' => 'trip', 'memory_evening_uuid' => 'memory-evening', 'capsule_uuid' => 'capsule', 'media_uuid' => 'media', 'import_uuid' => 'finance-import'] as $key => $prefix) {
            if (! empty($extra[$key])) return $prefix.':'.$extra[$key];
        }
        return $link ? 'link:'.mb_substr($link, 0, 150) : null;
    }

    private function validTime(string $value, string $fallback): string
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
    }
}
