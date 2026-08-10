<?php

namespace App\Http\Middleware;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\UserNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id'         => $request->user()->id,
                    'uuid'       => $request->user()->uuid,
                    'name'       => $request->user()->name,
                    'email'      => $request->user()->email,
                    'role'       => $request->user()->role,
                    'is_active'  => $request->user()->is_active,
                    'interface_density' => (is_array($request->user()->preferences) ? ($request->user()->preferences['interface_density'] ?? null) : null),
                    'theme' => (is_array($request->user()->preferences) ? ($request->user()->preferences['theme'] ?? null) : null),
                    'theme_palette' => \App\Support\ThemePalette::forUser($request->user()),
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
            ],
            // Which features this space may see. Lazily resolved so it costs nothing on
            // pages that never read it, and degrades to null before the migrations run.
            'features' => fn () => $this->activeFeatures($request),
            // The chat's opening state, so the dock paints instantly instead of after a
            // round trip. A closure, so it costs nothing on requests that never read it.
            'chatBootstrap' => fn () => $this->chatBootstrap($request),
            // This person's own arrangement of the menu; null means the built-in one.
            'navigation' => fn () => $this->navigation($request),
            // Public half of the VAPID pair; null until the deployment configures it, and
            // the toggle then says so instead of failing on a click.
            'push_public_key' => config('push.public_key'),
            'ziggy' => fn() => [
                ...(new \Tighten\Ziggy\Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /**
     * Feature codes the current space has switched on, used by the navigation to hide
     * what the customer does not want or is not entitled to.
     *
     * Returns null when there is nobody signed in or the catalogue has not been migrated
     * yet; the frontend treats null as "show everything" so nothing disappears mid-upgrade.
     *
     * @return list<string>|null
     */

    /**
     * Enough of the conversation to paint immediately: the most recent thread and its
     * last messages. The client still polls for everything after this, so the only thing
     * this removes is the blank moment on arrival.
     *
     * Returns null rather than throwing whenever the tables are not there yet, so a
     * deployment mid-migration degrades to the old behaviour instead of an error page.
     *
     * @return array<string, mixed>|null
     */
    /**
     * The navigation differences this person has saved, or null if they have none.
     *
     * Null rather than an empty array on purpose: the frontend treats null as "use the
     * built-in menu", and an empty array as "they hid everything", which are different
     * things that would otherwise look identical.
     *
     * @return list<array<string, mixed>>|null
     */
    private function navigation(Request $request): ?array
    {
        if (! $request->user() || ! Schema::hasTable('user_navigation_items')) return null;

        $rows = UserNavigationItem::where('user_id', $request->user()->id)
            ->orderBy('position')->get();

        if ($rows->isEmpty()) return null;

        return $rows->map(fn ($row) => [
            'href' => $row->href,
            'label' => $row->label,
            'icon' => $row->icon,
            'parent' => $row->parent_id ? $rows->firstWhere('id', $row->parent_id)?->href : null,
            'hidden' => (bool) $row->is_hidden,
            'group' => (bool) $row->is_group,
        ])->values()->all();
    }

    private function chatBootstrap(Request $request): ?array
    {
        if (! $request->user()) return null;
        if (! Schema::hasTable('conversations') || ! Schema::hasTable('chat_messages')) return null;

        try {
            $space = $request->user()->gallerySpaces()->orderByDesc('is_default')->first();
            if (! $space) return null;

            $conversation = Conversation::with('members')
                ->where('gallery_space_id', $space->id)
                ->forUser($request->user())
                ->orderByDesc('last_message_at')->orderByDesc('id')
                ->first();

            if (! $conversation) return null;

            $messages = ChatMessage::with('author:id,name,uuid,avatar_path,avatar_preset,avatar_colour')
                ->where('conversation_id', $conversation->id)
                ->latest('id')->limit(30)->get()->sortBy('id')->values();

            return [
                'conversation' => [
                    'uuid' => $conversation->uuid,
                    'kind' => $conversation->kind,
                    'title' => $conversation->titleFor($request->user()),
                    'icon' => $conversation->icon,
                ],
                'messages' => $messages->map(fn ($message) => [
                    'id' => (int) $message->id,
                    'uuid' => $message->uuid,
                    'body' => $message->body,
                    /*
                     | The opening batch has to carry its pictures.
                     |
                     | Sending null here was why a GIF appeared for whoever sent it and
                     | not for the other person: the sender's copy came back from the
                     | POST with its media, while the recipient's first paint came from
                     | here and had none — and a message already present is never
                     | re-fetched by the poll, so it stayed blank until a hard reload.
                     */
                    'media' => $message->media_path || $message->media_remote_url ? [
                        'url' => $message->media_remote_url ?: route('api.chat.media', ['uuid' => $message->uuid]),
                        'kind' => $message->media_remote_url
                            ? 'gif'
                            : ($message->attachment_type === 'voice' ? 'voice' : 'image'),
                        'duration_ms' => $message->attachment_type === 'voice' ? (int) $message->media_height : null,
                        'width' => $message->attachment_type === 'voice' ? null : $message->media_width,
                        'height' => $message->attachment_type === 'voice' ? null : $message->media_height,
                    ] : null,
                    'reactions' => [],
                    'author' => ['id' => $message->created_by, 'name' => $message->author?->name],
                    'is_mine' => $message->created_by === $request->user()->id,
                    'edited' => $message->edited_at !== null,
                    'sent_at' => $message->created_at?->toIso8601String(),
                ])->all(),
                'cursor' => (int) ($messages->last()->id ?? 0),
            ];
        } catch (Throwable) {
            // Never let a decoration take the whole page down.
            return null;
        }
    }

    private function activeFeatures(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || ! \Illuminate\Support\Facades\Schema::hasTable('features')) return null;

        $space = $user->gallerySpaces()->orderByDesc('is_default')->first();
        if (! $space) return null;

        $entitlements = app(\App\Services\Billing\EntitlementService::class);
        $entitled = $entitlements->entitledFeatures($space);

        return collect($entitled)
            ->filter(fn (string $code) => $entitlements->hasFeature($space, $code))
            ->values()->all();
    }
}
