<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatGame;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\Chat\GameEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Games inside a conversation.
 *
 * Starting one posts a message, so a game is found where it began rather than in a
 * separate list nobody visits. State is read through the same polling the chat already
 * does, which is why only turn-based games are offered.
 */
class ChatGameController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['kinds' => collect(GameEngine::KINDS)
            ->map(fn (array $meta, string $code) => $meta + ['code' => $code])->values()]);
    }

    public function store(Request $request, GameEngine $engine): JsonResponse
    {
        $this->available();
        $data = $request->validate([
            'conversation' => 'required|string',
            'kind' => 'required|string|in:' . implode(',', array_keys(GameEngine::KINDS)),
        ]);

        $conversation = $this->conversation($request, $data['conversation']);
        $players = $conversation->members->pluck('id')->values()->all();
        abort_if(count($players) < 2, 422, 'Ke hře jsou potřeba aspoň dva lidé.');

        // Two players: whoever started, and the other member. A group plays the first two.
        $players = array_values(array_unique([$request->user()->id, ...$players]));
        $players = array_slice($players, 0, 2);

        $game = ChatGame::create([
            'conversation_id' => $conversation->id,
            'gallery_space_id' => $conversation->gallery_space_id,
            'created_by' => $request->user()->id,
            'kind' => $data['kind'],
            'status' => 'playing',
            'state' => $engine->start($data['kind'], $players),
            'turn_user_id' => $data['kind'] === 'piskvorky' ? $players[0] : null,
        ]);

        $message = ChatMessage::create([
            'gallery_space_id' => $conversation->gallery_space_id,
            'conversation_id' => $conversation->id,
            'created_by' => $request->user()->id,
            'body' => '',
            'attachment_type' => 'game',
            'attachment_ref' => $game->uuid,
            'game_id' => $game->id,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return response()->json(['game' => $this->payload($game, $request), 'message_id' => $message->id], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $this->available();

        return response()->json($this->payload($this->game($request, $uuid), $request));
    }

    public function move(Request $request, string $uuid, GameEngine $engine): JsonResponse
    {
        $this->available();
        $game = $this->game($request, $uuid);

        $result = $engine->move($game, $request->user(), $request->all());
        abort_unless($result['ok'], 422, $result['error'] ?? 'Tah nelze provést.');

        $game->update([
            'state' => $result['state'],
            'turn_user_id' => $result['next'] ?? $game->turn_user_id,
            'winner_user_id' => $result['winner'] ?? null,
            'is_draw' => (bool) ($result['draw'] ?? false),
            'status' => ($result['finished'] ?? false) ? 'finished' : 'playing',
        ]);

        return response()->json($this->payload($game->fresh(), $request));
    }

    /** @return array<string, mixed> */
    private function payload(ChatGame $game, Request $request): array
    {
        $state = $game->state;

        // Rock-paper-scissors hides live choices: revealing them would decide the round.
        if ($game->kind === 'kamen') {
            $state['waiting_for'] = array_values(array_diff($state['players'], array_keys($state['choices'] ?? [])));
            $state['i_have_chosen'] = isset($state['choices'][$request->user()->id]);
            unset($state['choices']);
        }

        return [
            'uuid' => $game->uuid,
            'kind' => $game->kind,
            'name' => GameEngine::KINDS[$game->kind]['name'] ?? $game->kind,
            'status' => $game->status,
            'state' => $state,
            'my_turn' => $game->turn_user_id === $request->user()->id,
            'turn_user_id' => $game->turn_user_id,
            'winner_user_id' => $game->winner_user_id,
            'i_won' => $game->winner_user_id === $request->user()->id,
            'is_draw' => $game->is_draw,
        ];
    }

    private function game(Request $request, string $uuid): ChatGame
    {
        $game = ChatGame::where('uuid', $uuid)->firstOrFail();
        // Reachable only through a conversation the caller is in.
        $this->conversationById($request, $game->conversation_id);

        return $game;
    }

    private function conversation(Request $request, string $uuid): Conversation
    {
        return Conversation::with('members')->forUser($request->user())->where('uuid', $uuid)->firstOrFail();
    }

    private function conversationById(Request $request, int $id): Conversation
    {
        return Conversation::with('members')->forUser($request->user())->findOrFail($id);
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('chat_games'), 503, 'Pro hry dokončete databázové migrace.');
    }
}
