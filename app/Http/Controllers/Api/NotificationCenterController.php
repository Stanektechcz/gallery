<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationPreferenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class NotificationCenterController extends Controller
{
    private ?bool $stateColumnsAvailable = null;

    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'focus' => ['nullable', Rule::in(['all', 'important', 'unread'])],
            'category' => ['nullable', Rule::in(array_keys(NotificationPreferenceService::CATEGORIES))],
            'limit' => ['nullable', 'integer', 'min:5', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $storedPreferences = $this->preferences->preferences($user);
        $query = $user->notifications();
        if ($this->hasStateColumns()) {
            $query->whereNull('archived_at')
                ->where(fn ($state) => $state->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
        }
        $rows = $query->latest()->limit(300)->get();

        $all = $rows->map(fn (DatabaseNotification $notification) => $this->payload($notification))
            ->filter(fn (array $notification) => $this->preferences->allows($user, $notification['data']['type'], $notification['data']))
            ->values();
        $summary = [
            'total' => $all->count(),
            'unread' => $all->whereNull('read_at')->count(),
            'important' => $all->filter(fn (array $item) => in_array($item['priority'], ['high', 'critical'], true))->count(),
            'critical' => $all->where('priority', 'critical')->count(),
            'categories' => collect(array_keys(NotificationPreferenceService::CATEGORIES))->mapWithKeys(
                fn (string $category) => [$category => $all->where('category', $category)->count()]
            )->all(),
        ];

        $filtered = $all;
        if (($data['focus'] ?? 'all') === 'important') {
            $filtered = $filtered->filter(fn (array $item) => in_array($item['priority'], ['high', 'critical'], true));
        } elseif (($data['focus'] ?? 'all') === 'unread') {
            $filtered = $filtered->whereNull('read_at');
        }
        if (! empty($data['category'])) $filtered = $filtered->where('category', $data['category']);

        $filtered = $filtered->sort(function (array $left, array $right): int {
            if (($left['read_at'] === null) !== ($right['read_at'] === null)) return $left['read_at'] === null ? -1 : 1;
            $priority = $this->preferences->rank($right['priority']) <=> $this->preferences->rank($left['priority']);
            return $priority !== 0 ? $priority : strcmp($right['created_at'], $left['created_at']);
        })->values();
        $limit = (int) ($data['limit'] ?? 30);
        $page = (int) ($data['page'] ?? 1);
        $offset = ($page - 1) * $limit;

        return response()->json([
            'data' => $filtered->slice($offset, $limit)->values(),
            'meta' => $summary + [
                'page' => $page,
                'per_page' => $limit,
                'filtered_total' => $filtered->count(),
                'has_more' => $offset + $limit < $filtered->count(),
                'quiet_now' => $this->preferences->isQuiet($user),
            ],
            'preferences' => $storedPreferences,
            'categories' => NotificationPreferenceService::CATEGORIES,
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $this->notification($request, $id);
        $notification->markAsRead();

        return response()->json(['status' => 'read', 'id' => $notification->id]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', Rule::in(array_keys(NotificationPreferenceService::CATEGORIES))]]);
        $user = $request->user();
        $query = $user->unreadNotifications();
        if ($this->hasStateColumns()) $query->whereNull('archived_at');
        $notifications = $query->get()
            ->filter(function (DatabaseNotification $notification) use ($data, $user): bool {
                $payload = $this->payload($notification);
                return $this->preferences->allows($user, $payload['data']['type'], $payload['data'])
                    && (empty($data['category']) || $payload['category'] === $data['category']);
            });
        if ($notifications->isNotEmpty()) {
            $user->notifications()->whereIn('id', $notifications->pluck('id'))->update(['read_at' => now()]);
        }

        return response()->json(['status' => 'read', 'count' => $notifications->count()]);
    }

    public function snooze(Request $request, string $id): JsonResponse
    {
        abort_unless($this->hasStateColumns(), 503, 'Odkládání upozornění bude dostupné po dokončení databázové aktualizace.');
        $data = $request->validate(['minutes' => ['required', 'integer', Rule::in([60, 180, 1440, 10080])]]);
        $notification = $this->notification($request, $id);
        $until = now()->addMinutes((int) $data['minutes']);
        $notification->forceFill(['snoozed_until' => $until, 'read_at' => null])->save();

        return response()->json(['status' => 'snoozed', 'id' => $notification->id, 'snoozed_until' => $until->toIso8601String()]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        abort_unless($this->hasStateColumns(), 503, 'Archiv upozornění bude dostupný po dokončení databázové aktualizace.');
        $notification = $this->notification($request, $id);
        $notification->forceFill(['archived_at' => now(), 'read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['status' => 'archived', 'id' => $notification->id]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => $this->preferences->preferences($request->user()),
            'categories' => NotificationPreferenceService::CATEGORIES,
            'quiet_now' => $this->preferences->isQuiet($request->user()),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
            'priority_floor' => ['sometimes', Rule::in(NotificationPreferenceService::PRIORITIES)],
            'quiet' => ['sometimes', 'array'],
            'quiet.enabled' => ['sometimes', 'boolean'],
            'quiet.from' => ['sometimes', 'date_format:H:i'],
            'quiet.to' => ['sometimes', 'date_format:H:i'],
            'browser_notifications' => ['sometimes', 'boolean'],
        ]);
        $preferences = $this->preferences->update($request->user(), $data);

        return response()->json([
            'preferences' => $preferences,
            'categories' => NotificationPreferenceService::CATEGORIES,
            'quiet_now' => $this->preferences->isQuiet($request->user()->fresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;
        $data['type'] = (string) ($data['type'] ?? class_basename($notification->type));
        $meta = $this->preferences->metadata($data['type'], $data);
        $data['category'] = $meta['category'];
        $data['priority'] = $meta['priority'];
        $data['context_key'] = $meta['context_key'];

        return [
            'id' => $notification->id,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'snoozed_until' => $this->hasStateColumns() && $notification->snoozed_until ? Carbon::parse($notification->snoozed_until)->toIso8601String() : null,
            'category' => $meta['category'],
            'category_label' => $meta['category_label'],
            'priority' => $meta['priority'],
            'context_key' => $meta['context_key'],
            'data' => $data,
        ];
    }

    private function notification(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }

    private function hasStateColumns(): bool
    {
        return $this->stateColumnsAvailable ??= Schema::hasTable('notifications')
            && Schema::hasColumn('notifications', 'snoozed_until')
            && Schema::hasColumn('notifications', 'archived_at');
    }
}
