<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation list, and making new ones.
 *
 * Who may be talked to is bounded by the space: everyone the plan already lets you share
 * a gallery with, and nobody else. Within that, a person may hold one direct chat with
 * each member and as many groups as they like.
 */
class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->available();
        $user = $request->user();
        $space = $this->space($request);

        $conversations = Conversation::with(['members:id,name,avatar_path,last_seen_at', 'participants'])
            ->where('gallery_space_id', $space->id)
            ->forUser($user)
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->get();

        // Unread counts for every conversation in one query rather than one apiece.
        $unread = $this->unreadCounts($conversations, $user->id);

        return response()->json([
            'space_id' => $space->id,
            'me' => ['id' => $user->id, 'name' => $user->name],
            'conversations' => $conversations->map(fn (Conversation $row) => $this->payload($row, $user, $unread[$row->id] ?? 0))->values(),
            // Everyone this person is allowed to start a conversation with.
            'contacts' => $this->contacts($space, $user),
        ]);
    }

    /**
     * Opens the direct chat with someone, creating it the first time.
     *
     * Idempotent on purpose: tapping a name twice should land in the same conversation,
     * not make a second one.
     */
    public function direct(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $user = $request->user();
        $space = $this->space($request);

        $data = $request->validate(['user_id' => 'required|integer']);
        abort_if($data['user_id'] === $user->id, 422, 'Konverzaci sám se sebou založit nelze.');

        $other = $this->memberOrFail($space, (int) $data['user_id']);

        $existing = Conversation::where('gallery_space_id', $space->id)
            ->where('kind', Conversation::KIND_DIRECT)
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->whereHas('participants', fn ($query) => $query->where('user_id', $other->id))
            ->first();

        $conversation = $existing ?? DB::transaction(function () use ($space, $user, $other) {
            $created = Conversation::create([
                'gallery_space_id' => $space->id,
                'created_by' => $user->id,
                'kind' => Conversation::KIND_DIRECT,
                'last_message_at' => now(),
            ]);
            $created->participants()->createMany([
                ['user_id' => $user->id, 'role' => 'member'],
                ['user_id' => $other->id, 'role' => 'member'],
            ]);

            return $created;
        });

        return response()->json($this->payload($conversation->fresh(['members', 'participants']), $user, 0), $existing ? 200 : 201);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $user = $request->user();
        $space = $this->space($request);

        $data = $request->validate([
            'title' => 'required|string|max:120',
            'icon' => 'nullable|string|max:16',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer',
        ]);

        // Every invitee must already share the space; the creator is always in.
        $members = collect($data['user_ids'])->unique()
            ->reject(fn ($id) => (int) $id === $user->id)
            ->map(fn ($id) => $this->memberOrFail($space, (int) $id));

        $conversation = DB::transaction(function () use ($space, $user, $data, $members) {
            $created = Conversation::create([
                'gallery_space_id' => $space->id,
                'created_by' => $user->id,
                'kind' => Conversation::KIND_GROUP,
                'title' => $data['title'],
                'icon' => $data['icon'] ?? '💬',
                'last_message_at' => now(),
            ]);

            $created->participants()->create(['user_id' => $user->id, 'role' => 'owner']);
            foreach ($members as $member) {
                $created->participants()->create(['user_id' => $member->id, 'role' => 'member']);
            }

            return $created;
        });

        return response()->json($this->payload($conversation->fresh(['members', 'participants']), $user, 0), 201);
    }

    /** Renaming and membership changes, for the person who made the group. */
    public function updateGroup(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $user = $request->user();
        $conversation = $this->mine($request, $uuid);
        abort_unless($conversation->isGroup(), 422, 'Přímou konverzaci upravovat nelze.');
        $this->ownerOrFail($conversation, $user);

        $data = $request->validate([
            'title' => 'nullable|string|max:120',
            'icon' => 'nullable|string|max:16',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
        ]);

        $conversation->update(array_filter([
            'title' => $data['title'] ?? null,
            'icon' => $data['icon'] ?? null,
        ], fn ($value) => $value !== null));

        if (isset($data['user_ids'])) {
            $space = $this->space($request);
            $wanted = collect($data['user_ids'])->unique()
                ->map(fn ($id) => $this->memberOrFail($space, (int) $id)->id)
                // The owner cannot be removed from their own group by omission.
                ->push($user->id)->unique();

            $conversation->participants()->whereNotIn('user_id', $wanted)->delete();

            foreach ($wanted as $id) {
                ConversationParticipant::firstOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $id],
                    ['role' => $id === $user->id ? 'owner' : 'member'],
                );
            }
        }

        return response()->json($this->payload($conversation->fresh(['members', 'participants']), $user, 0));
    }

    /** Leaving is always allowed; the last one out takes the conversation with them. */
    public function leave(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $conversation = $this->mine($request, $uuid);
        abort_unless($conversation->isGroup(), 422, 'Z přímé konverzace odejít nelze, můžete ji jen smazat.');

        $conversation->participants()->where('user_id', $request->user()->id)->delete();
        if ($conversation->participants()->count() === 0) $conversation->delete();

        return response()->json(['left' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(Conversation $row, User $viewer, int $unread): array
    {
        $others = $row->members->where('id', '!=', $viewer->id);

        return [
            'uuid' => $row->uuid,
            'kind' => $row->kind,
            'title' => $row->titleFor($viewer),
            'icon' => $row->icon,
            'unread' => $unread,
            'last_message_at' => $row->last_message_at?->toIso8601String(),
            'is_owner' => $row->participants->firstWhere('user_id', $viewer->id)?->role === 'owner',
            'members' => $row->members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar_url ?? null,
                'last_seen_at' => $member->last_seen_at?->toIso8601String(),
                'is_me' => $member->id === $viewer->id,
            ])->values(),
            'others_count' => $others->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Conversation>  $conversations
     * @return array<int, int>
     */
    private function unreadCounts($conversations, int $userId): array
    {
        if ($conversations->isEmpty()) return [];

        $marks = $conversations->mapWithKeys(fn (Conversation $row) => [
            $row->id => $row->participants->firstWhere('user_id', $userId)?->last_read_message_id ?? 0,
        ]);

        $rows = ChatMessage::selectRaw('conversation_id, count(*) as total')
            ->whereIn('conversation_id', $marks->keys())
            ->where('created_by', '!=', $userId)
            ->where(function ($query) use ($marks) {
                foreach ($marks as $conversationId => $lastRead) {
                    $query->orWhere(fn ($inner) => $inner->where('conversation_id', $conversationId)->where('id', '>', $lastRead));
                }
            })
            ->groupBy('conversation_id')->pluck('total', 'conversation_id');

        return $rows->map(fn ($count) => (int) $count)->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contacts(GallerySpace $space, User $viewer): array
    {
        return $space->members()->where('users.id', '!=', $viewer->id)->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'avatar' => $member->avatar_url ?? null,
                'last_seen_at' => $member->last_seen_at?->toIso8601String(),
            ])->values()->all();
    }

    private function memberOrFail(GallerySpace $space, int $userId): User
    {
        $member = $space->members()->where('users.id', $userId)->first();
        abort_unless($member, 422, 'Tenhle uživatel není ve vašem prostoru.');

        return $member;
    }

    private function ownerOrFail(Conversation $conversation, User $user): void
    {
        $role = $conversation->participants->firstWhere('user_id', $user->id)?->role;
        abort_unless($role === 'owner', 403, 'Skupinu může upravit jen její zakladatel.');
    }

    private function mine(Request $request, string $uuid): Conversation
    {
        return Conversation::with(['members', 'participants'])
            ->where('gallery_space_id', $this->space($request)->id)
            ->forUser($request->user())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function space(Request $request): GallerySpace
    {
        $id = $request->integer('gallery_space_id') ?: null;
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze zakládat konverzace.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('conversations'), 503, 'Pro konverzace dokončete databázové migrace.');
    }
}
