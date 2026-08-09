<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatMessageRevision;
use App\Models\ChatReaction;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\GallerySpace;
use App\Support\AudioUploads;
use App\Services\Chat\MentionSearchService;
use App\Services\Integrations\GifSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    private const GIF_HOSTS = GifSearchService::HOSTS;

    /** Long enough that a pause reads as "still writing", short enough to expire on its own. */
    private const TYPING_SECONDS = 6;

    /** After this a member is no longer "in the conversation". */
    private const PRESENCE_SECONDS = 45;

    /**
     * How long a waiting poll holds the connection before answering empty.
     *
     * Short enough to stay well inside any proxy or FPM timeout, long enough that most
     * messages arrive on the same held request rather than on the next one.
     */
    private const HOLD_SECONDS = 20;

    /** How often a held request looks for new messages. */
    private const HOLD_INTERVAL_MS = 400;

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
        $around = $request->integer('around');
        $conversation = $this->conversation($request, $space);

        /*
         | Long polling: hold the request open until something arrives.
         |
         | This is what makes the chat feel immediate without a websocket server. The
         | request sleeps in short steps and returns the moment a newer message exists,
         | so delivery is bounded by the step rather than by a fixed interval.
         |
         | The session is closed first, and that is not optional. Laravel holds the
         | session lock for the whole request, so a poll sleeping twenty seconds would
         | block every other request from the same person — including the one sending
         | the message this poll is waiting for. Closing it early releases the lock; the
         | request is already authenticated and writes nothing to the session.
         |
         | The cost, stated plainly: a waiting poll occupies one PHP-FPM worker. For a
         | couple or a small group that is a handful of workers. An installation with
         | hundreds of simultaneous readers wants Reverb instead, and the payload here is
         | already shaped to be broadcast unchanged.
         */
        if ($request->boolean('wait') && $after > 0 && $around <= 0) {
            $request->session()->save();

            $deadline = microtime(true) + self::HOLD_SECONDS;
            while (microtime(true) < $deadline) {
                $arrived = ChatMessage::where('conversation_id', $conversation->id)
                    ->where('id', '>', $after)->exists();
                if ($arrived) break;

                // Somebody starting to type is also worth returning for: the dots should
                // appear while they write, not after they send.
                $typing = $this->hasTable('chat_presence') && DB::table('chat_presence')
                    ->where('gallery_space_id', $space->id)
                    ->where('user_id', '!=', $user->id)
                    ->where('typing_until', '>', now())
                    ->exists();
                if ($typing) break;

                usleep(self::HOLD_INTERVAL_MS * 1000);
            }
        }

        /*
         | Three shapes of read, and the client picks by what it is doing:
         |
         |   around=N  a window either side of one message, for jumping to a search hit
         |   after=N   everything newer, the ordinary live delta
         |   neither   the recent tail, for a first load
         */
        $base = fn () => ChatMessage::with('author:id,name,uuid,avatar_path,avatar_preset,avatar_colour')
            ->with('replyTo:id,uuid,body,created_by,attachment_type')
            ->where('conversation_id', $conversation->id);

        if ($around > 0) {
            $before = $base()->where('id', '<=', $around)->latest('id')->limit(30)->get();
            $behind = $base()->where('id', '>', $around)->orderBy('id')->limit(20)->get();
            $messages = $before->concat($behind)->sortBy('id')->values();
        } else {
            $messages = $base()
                ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
                ->orderBy('id')
                // A first load wants the recent tail, not a five-year backlog.
                ->when($after <= 0, fn ($query) => $query->latest('id')->limit(60))
                ->when($after > 0, fn ($query) => $query->limit(200))
                ->get()->sortBy('id')->values();
        }

        /*
         | Seeing the conversation is what marks it read; there is no separate button.
         |
         | The docked panel keeps polling while closed so its unread badge stays honest,
         | and says so with `peek`. Nobody is looking at a closed panel, so it must
         | neither clear the unread state nor claim the person is in the conversation.
         */
        $peeking = $request->boolean('peek');

        if ($messages->isNotEmpty() && ! $peeking) {
                $this->markRead($conversation->id, $user->id, (int) $messages->last()->id);
        }

        // One query for the whole batch, not one per message.
        $reactions = $this->reactionsFor($messages->pluck('id')->all(), $user->id);

        if (! $peeking) $this->touchPresence($space->id, $user->id);

        return response()->json([
            'space_id' => $space->id,
            'messages' => $messages->map(fn (ChatMessage $message) => $this->payload($message, $user->id, $reactions[$message->id] ?? []))->all(),
            'cursor' => (int) ($messages->last()->id ?? $after),
            'conversation' => [
                'uuid' => $conversation->uuid,
                'kind' => $conversation->kind,
                'title' => $conversation->titleFor($user),
                'icon' => $conversation->icon,
            ],
            'others' => $this->others($conversation, $user->id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $conversation = $this->conversation($request, $space);

        $data = $request->validate([
            // Optional, because a picture, a GIF or a recording is a message on its own.
            'body' => 'nullable|string|max:4000',
            'audio' => 'nullable|file|max:25600|' . AudioUploads::rule(),
            'duration_ms' => 'nullable|integer|between:200,1800000',
            'attachment_type' => 'nullable|string|in:recipe,media,event,place,trip',
            'attachment_ref' => 'nullable|string|max:190',
            'image' => 'nullable|file|max:' . self::IMAGE_MAX_KB . '|mimetypes:' . implode(',', self::IMAGE_MIME),
            // A GIF picked from the provider: we keep the link, not a copy.
            'gif_url' => 'nullable|url|max:600',
            'gif_width' => 'nullable|integer|max:4000',
            'gif_height' => 'nullable|integer|max:4000',
            // The message being answered, by uuid so the client never sees our ids.
            'reply_to' => 'nullable|string|max:64',
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $upload = $request->file('image') ?? $request->file('audio');
        $isVoice = $request->hasFile('audio');
        $gif = $this->safeGifUrl($data['gif_url'] ?? null);

        abort_if($body === '' && ! $upload && ! $gif, 422, 'Prázdnou zprávu odeslat nelze.');

        $path = $upload?->store("chat/{$space->id}", self::DISK) ?: null;

        // A reply must point inside the same conversation; anything else is not a reply.
        $replyTo = ! empty($data['reply_to'])
            ? ChatMessage::where('conversation_id', $conversation->id)->where('uuid', $data['reply_to'])->first()
            : null;

        $message = ChatMessage::create([
            'gallery_space_id' => $space->id,
            'conversation_id' => $conversation->id,
            'reply_to_id' => $replyTo?->id,
            'created_by' => $request->user()->id,
            // Voice notes are stored like any other attachment; the type is what tells
            // the client to draw a player instead of a picture.
            'attachment_type' => $isVoice ? 'voice' : ($data['attachment_type'] ?? null),
            'media_width' => $isVoice ? null : ($data['gif_width'] ?? null),
            'media_height' => $isVoice ? (int) ($data['duration_ms'] ?? 0) : ($data['gif_height'] ?? null),
            'body' => $body,
            'attachment_ref' => $data['attachment_ref'] ?? null,
            'media_path' => $path,
            'media_mime' => $upload?->getMimeType(),
            'media_size' => $upload?->getSize(),
            'media_remote_url' => $gif,
        ]);

        // Keeps the conversation list in order without counting messages.
        $conversation->forceFill(['last_message_at' => now()])->save();

        // Sending is reading: otherwise your own message comes back as unread.
        $this->markRead($conversation->id, $request->user()->id, (int) $message->id);

        return response()->json($this->payload($message->fresh('author'), $request->user()->id), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $message = $this->own($request, $uuid);

        /*
         | Once somebody has read it, it stands.
         |
         | Editing is for fixing what you just sent, not for changing what someone has
         | already acted on — a conversation where the other person's words can change
         | after they were read is not a record of anything.
         */
        $seenByOther = ConversationParticipant::where('conversation_id', $message->conversation_id)
            ->where('user_id', '!=', $message->created_by)
            ->where('last_read_message_id', '>=', $message->id)
            ->exists();

        abort_if($seenByOther, 422, 'Zprávu už někdo přečetl, upravit ji proto nelze.');

        $data = $request->validate(['body' => 'required|string|max:4000']);

        // Keep what it said before. History is never destroyed, only superseded.
        if ($this->hasTable('chat_message_revisions')) {
            ChatMessageRevision::create([
                'chat_message_id' => $message->id,
                'edited_by' => $request->user()->id,
                'body' => $message->body,
                'replaced_at' => now(),
            ]);
        }

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
    public function gifs(Request $request, GifSearchService $gifs): JsonResponse
    {
        $this->available();
        $request->validate(['q' => 'nullable|string|max:80']);

        return response()->json([
            'configured' => $gifs->configured(),
            'results' => $gifs->configured() ? $gifs->search($request->string('q')->toString()) : [],
        ]);
    }

    /**
     * Everything the detail sheet shows: when it was sent, who has read it and when.
     *
     * Read time is per participant, taken from how far each has read rather than from a
     * per-message receipt — one row per person stays one row however long the
     * conversation runs, and "read up to here" answers "have you seen this" exactly.
     */
    public function detail(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $message = ChatMessage::with('author:id,name,uuid')
            ->where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $conversation = Conversation::with('members')->forUser($request->user())
            ->findOrFail($message->conversation_id);

        $participants = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $message->created_by)->get();

        return response()->json([
            'uuid' => $message->uuid,
            'sent_at' => $message->created_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'author' => ['id' => $message->created_by, 'name' => $message->author?->name],
            'can_delete' => $message->created_by === $request->user()->id,
            'can_edit' => $message->created_by === $request->user()->id
                && ! ConversationParticipant::where('conversation_id', $message->conversation_id)
                    ->where('user_id', '!=', $message->created_by)
                    ->where('last_read_message_id', '>=', $message->id)->exists(),
            'edit_blocked_reason' => 'Zprávu už někdo přečetl, upravit ji proto nelze.',
            'body' => $message->body,
            'size_bytes' => $message->media_size,
            'kind' => $message->attachment_type ?: ($message->media_remote_url ? 'gif' : ($message->media_path ? 'image' : 'text')),
            'edits' => $this->hasTable('chat_message_revisions') ? $message->revisions()->count() : 0,
            'readers' => $participants->map(function (ConversationParticipant $row) use ($message, $conversation) {
                $member = $conversation->members->firstWhere('id', $row->user_id);
                $read = $row->last_read_message_id >= $message->id;

                return [
                    'id' => $row->user_id,
                    'name' => $member?->name,
                    'read' => $read,
                    // The moment they last read, which for this message is when it was seen.
                    'read_at' => $read ? $row->last_read_at?->toIso8601String() : null,
                ];
            })->values(),
        ]);
    }

    /**
     * Searches one conversation.
     *
     * Message bodies are encrypted, so the database cannot match them: LIKE over
     * ciphertext finds nothing. Matching therefore happens in PHP, over chunks pulled
     * newest-first, and stops as soon as enough hits are found — a search that answers
     * from the recent past instantly is worth more than one that reads five years of
     * history to prove a word was never used.
     *
     * The alternative, a plaintext search column, would undo the encryption for exactly
     * the content it protects, which is not a trade worth making for a find box.
     */
    public function search(Request $request): JsonResponse
    {
        $this->available();
        $request->validate(['q' => 'required|string|min:2|max:80']);

        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);
        $conversation = $this->conversation($request, $space);
        $needle = mb_strtolower(trim($request->string('q')->toString()));

        $hits = [];
        $scanned = 0;

        ChatMessage::with('author:id,name')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->chunk(200, function ($messages) use (&$hits, &$scanned, $needle) {
                foreach ($messages as $message) {
                    $scanned++;
                    if ($message->body === '' || ! str_contains(mb_strtolower($message->body), $needle)) continue;

                    $hits[] = [
                        'id' => (int) $message->id,
                        'uuid' => $message->uuid,
                        'excerpt' => $this->excerptAround($message->body, $needle),
                        'author' => $message->author?->name,
                        'sent_at' => $message->created_at?->toIso8601String(),
                    ];

                    if (count($hits) >= 40) return false;
                }

                // Far enough back that anything older is better found by scrolling.
                return $scanned < 4000;
            });

        return response()->json(['results' => $hits, 'scanned' => $scanned]);
    }

    /** A window around the match, so a result reads as a sentence rather than a word. */
    private function excerptAround(string $body, string $needle): string
    {
        $at = mb_stripos($body, $needle);
        if ($at === false) return Str::limit($body, 120);

        $from = max(0, $at - 40);
        $piece = mb_substr($body, $from, 140);

        return ($from > 0 ? '…' : '') . trim($piece) . (mb_strlen($body) > $from + 140 ? '…' : '');
    }

    /** Suggestions for what someone typed after "@". */
    public function mentions(Request $request, MentionSearchService $mentions): JsonResponse
    {
        $this->available();
        $request->validate(['q' => 'nullable|string|max:60']);
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        return response()->json($mentions->search($space, $request->user(), $request->string('q')->toString()));
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
                'kind' => $message->media_remote_url ? 'gif' : ($message->attachment_type === 'voice' ? 'voice' : 'image'),
                'duration_ms' => $message->attachment_type === 'voice' ? (int) $message->media_height : null,
                'mime' => $message->media_mime,
                'width' => $message->media_width,
                'height' => $message->media_height,
            ] : null,
            'reactions' => $reactions,
            /*
             | What this answers. The excerpt is carried rather than fetched so a quote
             | costs no extra query, and it is deliberately short: a quote is a pointer,
             | not a second copy of the message.
             */
            'reply_to' => $message->replyTo ? [
                'uuid' => $message->replyTo->uuid,
                'author_id' => $message->replyTo->created_by,
                'excerpt' => Str::limit(
                    $message->replyTo->body !== '' ? $message->replyTo->body : match ($message->replyTo->attachment_type) {
                        'voice' => 'Hlasovka', 'game' => 'Hra', default => 'Příloha',
                    },
                    90,
                ),
            ] : null,
            // A game invitation is a message whose body is the board.
            'game' => $message->attachment_type === 'game' ? $message->attachment_ref : null,
            'author' => [
                'id' => $message->created_by,
                'name' => $message->author?->name,
                'avatar' => $message->author?->avatar_url,
                'avatar_fallback' => $message->author?->avatar_fallback,
            ],
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
        if (! $messageIds || ! $this->hasTable('chat_reactions')) return [];

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
    /**
     * The other participants: present, in the conversation, typing, and how far read.
     *
     * Membership is read from the conversation itself. The previous version inferred it
     * from who happened to be in the space, which is why a roster could come back empty.
     *
     * @return list<array<string, mixed>>
     */
    private function others(Conversation $conversation, int $viewerId): array
    {
        $members = $conversation->members->where('id', '!=', $viewerId);
        if ($members->isEmpty()) return [];

        $presence = $this->hasTable('chat_presence')
            ? DB::table('chat_presence')->where('gallery_space_id', $conversation->gallery_space_id)->get()->keyBy('user_id')
            : collect();

        $reads = $conversation->participants->pluck('last_read_message_id', 'user_id');
        $now = now();

        return $members->map(function ($member) use ($presence, $reads, $now) {
            $row = $presence->get($member->id);
            $inChat = $row?->last_seen_at ? Carbon::parse($row->last_seen_at) : null;
            $inApp = $member->last_seen_at;

            // The later of the two: the chat's beat is finer, the app's is broader.
            $seen = $inApp && $inChat ? $inApp->max($inChat) : ($inApp ?? $inChat);

            return [
                'id' => (int) $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar_url,
                'avatar_fallback' => $member->avatar_fallback,
                'online' => $seen !== null && $seen->gt($now->copy()->subSeconds(self::PRESENCE_SECONDS)),
                'in_chat' => $inChat !== null && $inChat->gt($now->copy()->subSeconds(self::PRESENCE_SECONDS)),
                'typing' => $row?->typing_until !== null && Carbon::parse($row->typing_until)->gt($now),
                'last_seen_at' => $seen?->toIso8601String(),
                'read_up_to' => (int) ($reads[$member->id] ?? 0),
            ];
        })->values()->all();
    }

    private function markRead(int $conversationId, int $userId, int $messageId): void
    {
        ConversationParticipant::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            // Never move the mark backwards: an old delta must not un-read newer messages.
            ->where('last_read_message_id', '<', $messageId)
            ->update(['last_read_message_id' => $messageId, 'last_read_at' => now()]);
    }

    /**
     * The conversation being read, defaulting to the most recent one this person is in.
     *
     * Falls back to creating the space-wide group when somebody has none at all, so a
     * fresh install opens on something rather than an error.
     */
    private function conversation(Request $request, GallerySpace $space): Conversation
    {
        $user = $request->user();
        $uuid = $request->string('conversation')->toString();

        if ($uuid !== '') {
            return Conversation::with('members')
                ->where('gallery_space_id', $space->id)->forUser($user)->where('uuid', $uuid)->firstOrFail();
        }

        $recent = Conversation::with('members')
            ->where('gallery_space_id', $space->id)->forUser($user)
            ->orderByDesc('last_message_at')->orderByDesc('id')->first();

        if ($recent) return $recent;

        $created = Conversation::create([
            'gallery_space_id' => $space->id,
            'created_by' => $user->id,
            'kind' => Conversation::KIND_GROUP,
            'title' => 'Společná konverzace',
            'icon' => '💬',
            'last_message_at' => now(),
        ]);

        foreach ($space->members()->pluck('users.id') as $memberId) {
            $created->participants()->create(['user_id' => $memberId]);
        }

        return $created->fresh('members');
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

    /** @var array<string, bool> */
    private array $tables = [];

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    /**
     * Both tables, not just one.
     *
     * Checking only chat_messages meant that a deployment which had not yet run the
     * conversations migration sailed past this and died further in with a 500, which is
     * what "Server Error on opening the chat" was. A missing migration should say so.
     */
    private function available(): void
    {
        abort_unless(
            $this->hasTable('chat_messages') && $this->hasTable('conversations') && $this->hasTable('conversation_participants'),
            503,
            'Chat čeká na dokončení databázových migrací. Spusťte na serveru: php artisan migrate --force',
        );
    }
}
