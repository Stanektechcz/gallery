<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\GallerySpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // Seeing the conversation is what marks it read; there is no separate button.
        if ($messages->isNotEmpty()) {
            $this->markRead($space->id, $user->id, (int) $messages->last()->id);
        }

        $this->touchPresence($space->id, $user->id);

        return response()->json([
            'space_id' => $space->id,
            'messages' => $messages->map(fn (ChatMessage $message) => $this->payload($message, $user->id))->all(),
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
            'body' => 'required|string|max:4000',
            'attachment_type' => 'nullable|string|in:recipe,media,event,place,trip',
            'attachment_ref' => 'nullable|string|max:190',
        ]);

        $message = ChatMessage::create([
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
            'body' => trim($data['body']),
            'attachment_type' => $data['attachment_type'] ?? null,
            'attachment_ref' => $data['attachment_ref'] ?? null,
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

    /** @return array<string, mixed> */
    private function payload(ChatMessage $message, int $viewerId): array
    {
        return [
            'id' => (int) $message->id,
            'uuid' => $message->uuid,
            'body' => $message->body,
            'attachment' => $message->attachment_type
                ? ['type' => $message->attachment_type, 'ref' => $message->attachment_ref]
                : null,
            'author' => ['id' => $message->created_by, 'name' => $message->author?->name],
            'is_mine' => $message->created_by === $viewerId,
            'edited' => $message->edited_at !== null,
            'sent_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * The other members' state: here, typing, and how far they have read.
     *
     * @return list<array<string, mixed>>
     */
    private function others(int $spaceId, int $viewerId): array
    {
        if (! Schema::hasTable('chat_presence')) return [];

        $presence = DB::table('chat_presence')
            ->join('users', 'users.id', '=', 'chat_presence.user_id')
            ->where('chat_presence.gallery_space_id', $spaceId)
            ->where('chat_presence.user_id', '!=', $viewerId)
            ->select('users.id', 'users.name', 'chat_presence.last_seen_at', 'chat_presence.typing_until')
            ->get();

        $reads = DB::table('chat_reads')
            ->where('gallery_space_id', $spaceId)
            ->pluck('last_read_message_id', 'user_id');

        $now = now();

        return $presence->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'online' => $row->last_seen_at !== null
                && Carbon::parse($row->last_seen_at)->gt($now->copy()->subSeconds(self::PRESENCE_SECONDS)),
            'typing' => $row->typing_until !== null && Carbon::parse($row->typing_until)->gt($now),
            'read_up_to' => (int) ($reads[$row->id] ?? 0),
        ])->all();
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
