<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\ConversationParticipant;
use App\Models\ConversationTag;
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

        $conversations = Conversation::with(['members:id,name,avatar_path,last_seen_at', 'participants', 'category', 'tags'])
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
            'categories' => ConversationCategory::where('gallery_space_id', $space->id)
                ->orderBy('position')->orderBy('name')->get()
                ->map(fn (ConversationCategory $row) => [
                    'uuid' => $row->uuid, 'name' => $row->name, 'icon' => $row->icon,
                ])->values(),
            'tags' => ConversationTag::where('gallery_space_id', $space->id)->orderBy('name')->get()
                ->map(fn (ConversationTag $row) => [
                    'uuid' => $row->uuid, 'name' => $row->name, 'colour' => $row->colour,
                ])->values(),
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

    /**
     * Creates a channel.
     *
     * Open by default, which is what makes a channel a channel: it is there for everyone
     * in the space without anybody being invited. A private one falls back to the same
     * participant list that groups use.
     */
    public function storeChannel(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $user = $request->user();
        $space = $this->space($request);

        $data = $request->validate([
            'title' => 'required|string|max:120',
            'topic' => 'nullable|string|max:190',
            'icon' => 'nullable|string|max:16',
            'category' => 'nullable|string|max:64',
            'visibility' => 'nullable|in:open,invite',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
        ]);

        $category = ! empty($data['category'])
            ? ConversationCategory::where('gallery_space_id', $space->id)->where('uuid', $data['category'])->first()
            : null;

        $visibility = $data['visibility'] ?? Conversation::VISIBILITY_OPEN;

        $channel = DB::transaction(function () use ($space, $user, $data, $category, $visibility) {
            $created = Conversation::create([
                'gallery_space_id' => $space->id,
                'conversation_category_id' => $category?->id,
                'created_by' => $user->id,
                'kind' => Conversation::KIND_CHANNEL,
                'visibility' => $visibility,
                'title' => $data['title'],
                'topic' => $data['topic'] ?? null,
                'icon' => $data['icon'] ?? null,
                'last_message_at' => now(),
            ]);

            // The creator is a participant even in an open channel, so it has an owner.
            $created->participants()->create(['user_id' => $user->id, 'role' => 'owner']);

            if ($visibility === Conversation::VISIBILITY_INVITE) {
                foreach (collect($data['user_ids'] ?? [])->unique()->reject(fn ($id) => (int) $id === $user->id) as $id) {
                    $created->participants()->create(['user_id' => $this->memberOrFail($space, (int) $id)->id]);
                }
            }

            return $created;
        });

        return response()->json($this->payload($channel->fresh(['members', 'participants', 'category', 'tags']), $user, 0), 201);
    }

    /** Channel settings: name, topic, category, tags, archiving. */
    public function updateChannel(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $user = $request->user();
        $space = $this->space($request);
        $channel = $this->mine($request, $uuid);
        abort_unless($channel->isChannel(), 422, 'Tohle není kanál.');
        $this->ownerOrFail($channel, $user);

        $data = $request->validate([
            'title' => 'nullable|string|max:120',
            'topic' => 'nullable|string|max:190',
            'icon' => 'nullable|string|max:16',
            'category' => 'nullable|string|max:64',
            'is_archived' => 'nullable|boolean',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
        ]);

        $category = ! empty($data['category'])
            ? ConversationCategory::where('gallery_space_id', $space->id)->where('uuid', $data['category'])->first()
            : null;

        $channel->update(array_filter([
            'title' => $data['title'] ?? null,
            'topic' => $data['topic'] ?? null,
            'icon' => $data['icon'] ?? null,
            'conversation_category_id' => $category?->id,
            'is_archived' => $data['is_archived'] ?? null,
        ], fn ($value) => $value !== null));

        if (isset($data['tags'])) {
            $channel->tags()->sync(
                ConversationTag::where('gallery_space_id', $space->id)->whereIn('uuid', $data['tags'])->pluck('id'),
            );
        }

        return response()->json($this->payload($channel->fresh(['members', 'participants', 'category', 'tags']), $user, 0));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'icon' => 'nullable|string|max:16',
        ]);

        $category = ConversationCategory::create([
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'position' => (int) ConversationCategory::where('gallery_space_id', $space->id)->max('position') + 1,
        ]);

        return response()->json(['uuid' => $category->uuid, 'name' => $category->name, 'icon' => $category->icon], 201);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:40',
            'colour' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        // Reaching for a tag that already exists is the intent, not a clash.
        $tag = ConversationTag::firstOrCreate(
            ['gallery_space_id' => $space->id, 'name' => $data['name']],
            ['colour' => $data['colour'] ?? '#7c5cff'],
        );

        return response()->json(['uuid' => $tag->uuid, 'name' => $tag->name, 'colour' => $tag->colour], 201);
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
            'visibility' => $row->visibility,
            'topic' => $row->topic,
            'is_default' => (bool) $row->is_default,
            'is_archived' => (bool) $row->is_archived,
            'category' => $row->category ? ['uuid' => $row->category->uuid, 'name' => $row->category->name, 'icon' => $row->category->icon] : null,
            'tags' => $row->tags->map(fn ($tag) => ['uuid' => $tag->uuid, 'name' => $tag->name, 'colour' => $tag->colour])->values(),
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
