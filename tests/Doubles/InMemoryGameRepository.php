<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Closure;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Shared\Domain\ValueObjects\RequestId;

/**
 * In-memory repository for the tests.
 *
 * It lives in `tests/` and not in `src/` on purpose: it is a double, not an
 * implementation. It allows handlers and whole HTTP routes to be tested without a
 * database, which is exactly what is needed while the environment has no `pdo_sqlite`.
 */
final class InMemoryGameRepository implements GameRepository
{
    /** @var array<string, Game> */
    private array $games = [];

    /**
     * The idempotency ledger, which the real implementation keeps in
     * `game_moves.request_id`: one write intention, one game and one kind.
     *
     * @var array<string, array{game: string, kind: string}>
     */
    private array $requests = [];

    public function find(GameId $id): ?Game
    {
        return $this->games[$id->value()] ?? null;
    }

    /**
     * There is one process and one array here, so there is nothing to lock: the
     * double keeps the method because the protocol reads through it.
     */
    public function findForUpdate(GameId $id): ?Game
    {
        return $this->find($id);
    }

    public function transactional(Closure $work): mixed
    {
        return $work();
    }

    public function findByJoinCode(JoinCode $code): ?Game
    {
        foreach ($this->games as $game) {
            if ($game->joinCode()->equals($code)) {
                return $game;
            }
        }

        return null;
    }

    /**
     * @return array{game: GameId, kind: string}|null
     */
    public function intentionOfRequest(RequestId $requestId): ?array
    {
        $entry = $this->requests[$requestId->value()] ?? null;

        return $entry === null
            ? null
            : ['game' => GameId::fromString($entry['game']), 'kind' => $entry['kind']];
    }

    public function save(Game $game, ?RequestId $requestId = null): void
    {
        // Same as the real implementation: persisting consumes the pending log,
        // an intention is recorded only when the save appended something, and it
        // lands on the first entry, which is what carries its kind.
        $moves = $game->pullMoves();

        if ($requestId !== null && $moves !== []) {
            $this->requests[$requestId->value()] = [
                'game' => $game->id()->value(),
                'kind' => $moves[0]->kind(),
            ];
        }

        $this->games[$game->id()->value()] = $game;
    }

    public function clear(): void
    {
        $this->games = [];
        $this->requests = [];
    }
}
