<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatReaction;
use App\Models\GallerySpace;
use App\Services\Integrations\TenorGifClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Live conversation for a space.
 *
 * There is no websocket here, and that is a decision rather than an omission: this app
 * runs on PHP-FPM with no long-running process, so a socket server would be a second
 * thing to deploy, supervise and restart. Instead the client asks for "everything after
 * message N" and the server answers with only that — an empty poll is one indexed lookup
 * returning no rows, which is cheap enough to run every couple of seconds for a couple.
 *
 * The payload is shaped so that swapping in a broadcast driver later changes the
 * transport and nothing else: the same delta the poll returns is what an event would
 * carry.
 */
class ChatController extends Controller
{
    private const DISK = 'local';

    /** Phone photos are large; this is generous without letting a chat fill the quota. */
    private const IMAGE_MAX_KB = 12288;

    /** @var list<string> */
    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Providers whose GIF links we are willing to render.
     *
     * A remote image URL is a tracking pixel with extra steps: whoever hosts it learns
     * the reader's address and when they opened the conversation. Restricting it to the
     * picker's own CDNs means a message cannot smuggle in an arbitrary beacon.
     *
     * @var list<string>
     */
    private const GIF_HOSTS = ['media.tenor.com', 'c.tenor.com', 'tenor.com'];

    /** Long enough that a pause reads as "still writing", short enough to expire on its own. */
    private const TYPING_SECONDS = 6;

    /** After this a member is no longer "in the conversation". */
    private const PRESENCE_SECONDS = 45;

    /**
     * One endpoint for the whole live view: new messages, who is here, who is typing.
     *
     * `after` makes it a delta. Without it the client gets the tail of the conversation,
     * which is what a fresh page load needs.
     */
    public function poll(Request $request): JsonResponse
    {
        $this->available();
        $user = $request->user();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $after = $request->integer('after');

        $messages = ChatMessage::with('author:id,name')
            ->where('gallery_space_id', $space->id)
            ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
            ->orderBy('id')
            // A first load wants the recent tail, not a five-year backlog.
            ->when($after <= 0, fn ($query) => $query->latest('id')->limit(60))
            ->when($after > 0, fn ($query) => $query->limit(200))
            ->get()
            ->sortBy('id')
            ->values();

        /*
         | Seeing the conversation is what marks it read; there is no separate button.
         |
         | The docked panel keeps polling while closed so its unread badge stays honest,
         | and says so with `peek`. Nobody is looking at a closed panel, so it must
         | neither clear the unread state nor claim the person is in the conversation.
         */
        $peeking = $request->boolean('peek');

        if ($messages->isNotEmpty() && ! $peeking) {
            $this->markRead($space->id, $user->id, (int) $messages->last()->id);
        }

        // One query for the whole batch, not one per message.
        $reactions = $this->reactionsFor($messages->pluck('id')->all(), $user->id);

        if (! $peeking) $this->touchPresence($space->id, $user->id);

        return response()->json([
            'space_id' => $space->id,
            'messages' => $messages->map(fn (ChatMessage $message) => $this->payload($message, $user->id, $reactions[$message->id] ?? []))->all(),
            'cursor' => (int) ($messages->last()->id ?? $after),
            'others' => $this->others($space->id, $user->id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $data = $request->validate([
            // Optional, because a picture or a GIF is a message on its own.
            'body' => 'nullable|string|max:4000',
            'attachment_type' => 'nullable|string|in:recipe,media,event,place,trip',
            'attachment_ref' => 'nullable|string|max:190',
            'image' => 'nullable|file|max:' . self::IMAGE_MAX_KB . '|mimetypes:' . implode(',', self::IMAGE_MIME),
            // A GIF picked from the provider: we keep the link, not a copy.
            'gif_url' => 'nullable|url|max:600',
            'gif_width' => 'nullable|integer|max:4000',
            'gif_height' => 'nullable|integer|max:4000',
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $upload = $request->file('image');
        $gif = $this->safeGifUrl($data['gif_url'] ?? null);

        abort_if($body === '' && ! $upload && ! $gif, 422, 'Prázdnou zprávu odeslat nelze.');

        $path = $upload?->store("chat/{$space->id}", self::DISK) ?: null;

        $message = ChatMessage::create([
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
            'body' => $body,
            'attachment_type' => $data['attachment_type'] ?? null,
            'attachment_ref' => $data['attachment_ref'] ?? null,
            'media_path' => $path,
            'media_mime' => $upload?->getMimeType(),
            'media_size' => $upload?->getSize(),
            'media_remote_url' => $gif,
            'media_width' => $data['gif_width'] ?? null,
            'media_height' => $data['gif_height'] ?? null,
        ]);

        // Sending is reading: otherwise your own message comes back as unread.
        $this->markRead($space->id, $request->user()->id, (int) $message->id);

        return response()->json($this->payload($message->fresh('author'), $request->user()->id), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $message = $this->own($request, $uuid);

        $data = $request->validate(['body' => 'required|string|max:4000']);
        $message->update(['body' => trim($data['body']), 'edited_at' => now()]);

        return response()->json($this->payload($message->fresh('author'), $request->user()->id));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $this->own($request, $uuid)->delete();

        return response()->json(['deleted' => true]);
    }

    /** Announces typing. Expires by itself, so a closed tab stops claiming to type. */
    public function typing(Request $request): JsonResponse
    {
        $this->available();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);
        $this->touchPresence($space->id, $request->user()->id, typing: true);

        return response()->json(['ok' => true]);
    }

    /**
     * GIF search. Answers with an empty list and configured=false when no key is set,
     * so the client can hide the picker instead of showing one that never finds anything.
     */
    public function gifs(Request $request, TenorGifClient $tenor): JsonResponse
    {
        $this->available();
        $request->validate(['q' => 'nullable|string|max:80']);

        return response()->json([
            'configured' => $tenor->configured(),
            'results' => $tenor->configured() ? $tenor->search($request->string('q')->toString()) : [],
        ]);
    }

    /** Streams an uploaded picture. Private disk, authorised caller, never a public URL. */
    public function media(Request $request, string $uuid)
    {
        $this->available();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $message = ChatMessage::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();
        abort_unless($message->media_path && Storage::disk(self::DISK)->exists($message->media_path), 404);

        return Storage::disk(self::DISK)->response($message->media_path, null, [
            'Content-Type' => $message->media_mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Toggles one emoji for the caller. Sending the same one again takes it back. */
    public function react(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $data = $request->validate([
            // Length rather than a fixed list: a family emoji is several codepoints and
            // a skin tone adds more, and none of that changes what we store it for.
            'emoji' => 'required|string|max:16',
        ]);

        $message = ChatMessage::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $existing = ChatReaction::where('chat_message_id', $message->id)
            ->where('user_id', $request->user()->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) $existing->delete();
        else ChatReaction::create([
            'chat_message_id' => $message->id,
            'user_id' => $request->user()->id,
            'emoji' => $data['emoji'],
        ]);

        return response()->json([
            'uuid' => $message->uuid,
            'reactions' => $this->reactionsFor([$message->id], $request->user()->id)[$message->id] ?? [],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(ChatMessage $message, int $viewerId, array $reactions = []): array
    {
        return [
            'id' => (int) $message->id,
            'uuid' => $message->uuid,
            'body' => $message->body,
            'attachment' => $message->attachment_type
                ? ['type' => $message->attachment_type, 'ref' => $message->attachment_ref]
                : null,
            'media' => $message->media_path || $message->media_remote_url ? [
                // Uploads go through us; a provider's GIF stays where it already lives.
                'url' => $message->media_remote_url ?: route('api.chat.media', ['uuid' => $message->uuid]),
                'kind' => $message->media_remote_url ? 'gif' : 'image',
                'mime' => $message->media_mime,
                'width' => $message->media_width,
                'height' => $message->media_height,
            ] : null,
            'reactions' => $reactions,
            'author' => ['id' => $message->created_by, 'name' => $message->author?->name],
            'is_mine' => $message->created_by === $viewerId,
            'edited' => $message->edited_at !== null,
            'sent_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * Reactions for a batch of messages, grouped by emoji, in one query.
     *
     * @param  list<int>  $messageIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function reactionsFor(array $messageIds, int $viewerId): array
    {
        if (! $messageIds || ! Schema::hasTable('chat_reactions')) return [];

        return ChatReaction::whereIn('chat_message_id', $messageIds)->get()
            ->groupBy('chat_message_id')
            ->map(fn ($rows) => $rows->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'mine' => $group->contains('user_id', $viewerId),
                ])->values()->all())
            ->all();
    }

    /** Accepts a GIF link only from the picker's own hosts; see GIF_HOSTS. */
    private function safeGifUrl(?string $url): ?string
    {
        if (! $url) return null;

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        abort_unless($scheme === 'https' && in_array($host, self::GIF_HOSTS, true), 422, 'Tenhle zdroj GIFů nepodporujeme.');

        return $url;
    }

    /**
     * The other members' state: here, typing, and how far they have read.
     *
     * @return list<array<string, mixed>>
     */
    private function others(int $spaceId, int $viewerId): array
    {
        // Everyone in the space, not only those who have opened the chat: a partner
        // reading the calendar is present in the app and should look it.
        $members = DB::table('gallery_space_user')
            ->join('users', 'users.id', '=', 'gallery_space_user.user_id')
            ->where('gallery_space_user.gallery_space_id', $spaceId)
            ->where('users.id', '!=', $viewerId)
            ->select('users.id', 'users.name', 'users.last_seen_at')
            ->get();

        if ($members->isEmpty()) return [];

        $presence = Schema::hasTable('chat_presence')
            ? DB::table('chat_presence')->where('gallery_space_id', $spaceId)->get()->keyBy('user_id')
            : collect();

        $reads = DB::table('chat_reads')->where('gallery_space_id', $spaceId)->pluck('last_read_message_id', 'user_id');

        $now = now();

        return $members->map(function ($member) use ($presence, $reads, $now) {
            $row = $presence->get($member->id);
            $inChat = $row?->last_seen_at ? Carbon::parse($row->last_seen_at) : null;
            $inApp = $member->last_seen_at ? Carbon::parse($member->last_seen_at) : null;

            // The later of the two: the chat's own beat is finer, the app's is broader.
            $seen = $inApp && $inChat ? $inApp->max($inChat) : ($inApp ?? $inChat);

            return [
                'id' => (int) $member->id,
                'name' => $member->name,
                'online' => $seen !== null && $seen->gt($now->copy()->subSeconds(self::PRESENCE_SECONDS)),
                // Distinguishes "here in the conversation" from "somewhere in the app".
                'in_chat' => $inChat !== null && $inChat->gt($now->copy()->subSeconds(self::PRESENCE_SECONDS)),
                'typing' => $row?->typing_until !== null && Carbon::parse($row->typing_until)->gt($now),
                'last_seen_at' => $seen?->toIso8601String(),
                'read_up_to' => (int) ($reads[$member->id] ?? 0),
            ];
        })->values()->all();
    }

    private function markRead(int $spaceId, int $userId, int $messageId): void
    {
        DB::table('chat_reads')->upsert([[
            'gallery_space_id' => $spaceId,
            'user_id' => $userId,
            'last_read_message_id' => $messageId,
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['gallery_space_id', 'user_id'], ['last_read_message_id', 'read_at', 'updated_at']);
    }

    private function touchPresence(int $spaceId, int $userId, bool $typing = false): void
    {
        $row = [
            'gallery_space_id' => $spaceId,
            'user_id' => $userId,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $update = ['last_seen_at', 'updated_at'];

        if ($typing) {
            $row['typing_until'] = now()->addSeconds(self::TYPING_SECONDS);
            $update[] = 'typing_until';
        }

        DB::table('chat_presence')->upsert([$row], ['gallery_space_id', 'user_id'], $update);
    }

    private function own(Request $request, string $uuid): ChatMessage
    {
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $message = ChatMessage::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();
        abort_unless($message->created_by === $request->user()->id, 403, 'Upravit lze jen vlastní zprávu.');

        return $message;
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze psát zprávy.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('chat_messages'), 503, 'Pro chat dokončete databázové migrace.');
    }
}
