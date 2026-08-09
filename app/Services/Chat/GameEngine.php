<?php

namespace App\Services\Chat;

use App\Models\ChatGame;
use App\Models\User;

/**
 * The rules of the games, kept away from the controller.
 *
 * The server owns the board. A client sends "I play square four" and this decides whether
 * that was legal, what the board becomes and whose turn it is — never the other way
 * round, because a client that can post a board can post a won one.
 */
class GameEngine
{
    /** @var array<string, array{name: string, icon: string, players: int}> */
    public const KINDS = [
        'piskvorky' => ['name' => 'Piškvorky', 'icon' => '⭕', 'players' => 2],
        'kamen' => ['name' => 'Kámen, nůžky, papír', 'icon' => '✊', 'players' => 2],
    ];

    /** @param  list<int>  $playerIds */
    public function start(string $kind, array $playerIds): array
    {
        return match ($kind) {
            'piskvorky' => [
                // Nine squares, null until played, then the id of whoever took it.
                'board' => array_fill(0, 9, null),
                'players' => $playerIds,
                'marks' => [$playerIds[0] => '❌', $playerIds[1] ?? $playerIds[0] => '⭕'],
            ],
            'kamen' => [
                'players' => $playerIds,
                // Hidden until both have chosen, or the second player would simply win.
                'choices' => [],
                'round' => 1,
                'score' => array_fill_keys($playerIds, 0),
            ],
            default => [],
        };
    }

    /**
     * Applies one move and returns the new state.
     *
     * @return array{ok: bool, error?: string, state?: array<string, mixed>, next?: ?int, winner?: ?int, draw?: bool, finished?: bool}
     */
    public function move(ChatGame $game, User $player, array $move): array
    {
        if ($game->status !== 'playing') return ['ok' => false, 'error' => 'Hra už skončila.'];

        $state = $game->state;
        if (! in_array($player->id, $state['players'] ?? [], true)) {
            return ['ok' => false, 'error' => 'V téhle hře nehrajete.'];
        }

        return match ($game->kind) {
            'piskvorky' => $this->playNoughts($game, $player, $state, $move),
            'kamen' => $this->playRps($game, $player, $state, $move),
            default => ['ok' => false, 'error' => 'Neznámá hra.'],
        };
    }

    private function playNoughts(ChatGame $game, User $player, array $state, array $move): array
    {
        if ($game->turn_user_id !== $player->id) return ['ok' => false, 'error' => 'Nejste na tahu.'];

        $square = (int) ($move['square'] ?? -1);
        if ($square < 0 || $square > 8) return ['ok' => false, 'error' => 'Takové pole neexistuje.'];
        if ($state['board'][$square] !== null) return ['ok' => false, 'error' => 'Tohle pole už je obsazené.'];

        $state['board'][$square] = $player->id;

        $winner = $this->threeInARow($state['board']);
        $full = ! in_array(null, $state['board'], true);
        $others = array_values(array_diff($state['players'], [$player->id]));

        return [
            'ok' => true,
            'state' => $state,
            'next' => $winner || $full ? null : ($others[0] ?? null),
            'winner' => $winner,
            'draw' => ! $winner && $full,
            'finished' => (bool) $winner || $full,
        ];
    }

    /** @param  list<int|null>  $board */
    private function threeInARow(array $board): ?int
    {
        $lines = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];

        foreach ($lines as [$a, $b, $c]) {
            if ($board[$a] !== null && $board[$a] === $board[$b] && $board[$b] === $board[$c]) return $board[$a];
        }

        return null;
    }

    private function playRps(ChatGame $game, User $player, array $state, array $move): array
    {
        $choice = (string) ($move['choice'] ?? '');
        if (! in_array($choice, ['kamen', 'nuzky', 'papir'], true)) {
            return ['ok' => false, 'error' => 'Vyberte kámen, nůžky nebo papír.'];
        }
        if (isset($state['choices'][$player->id])) return ['ok' => false, 'error' => 'V tomhle kole už jste volili.'];

        $state['choices'][$player->id] = $choice;

        // Both in: reveal, score, and start the next round.
        if (count($state['choices']) < count($state['players'])) {
            return ['ok' => true, 'state' => $state, 'next' => null, 'winner' => null, 'draw' => false, 'finished' => false];
        }

        [$first, $second] = $state['players'];
        $roundWinner = $this->rpsWinner($state['choices'][$first], $state['choices'][$second], $first, $second);

        $state['reveal'] = $state['choices'];
        if ($roundWinner) $state['score'][$roundWinner]++;
        $state['choices'] = [];
        $state['round']++;

        // Three rounds decides it; a draw simply replays.
        $best = max($state['score']);
        $finished = $best >= 3;
        $winner = $finished ? (int) array_search($best, $state['score'], true) : null;

        return [
            'ok' => true,
            'state' => $state,
            'next' => null,
            'winner' => $winner,
            'draw' => false,
            'finished' => $finished,
        ];
    }

    private function rpsWinner(string $a, string $b, int $first, int $second): ?int
    {
        if ($a === $b) return null;
        $beats = ['kamen' => 'nuzky', 'nuzky' => 'papir', 'papir' => 'kamen'];

        return $beats[$a] === $b ? $first : $second;
    }
}
