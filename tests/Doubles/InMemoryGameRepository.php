<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;

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

    public function find(GameId $id): ?Game
    {
        return $this->games[$id->value()] ?? null;
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

    public function save(Game $game): void
    {
        // Same as the real implementation: persisting consumes the pending log.
        $game->pullMoves();

        $this->games[$game->id()->value()] = $game;
    }

    public function clear(): void
    {
        $this->games = [];
    }
}
