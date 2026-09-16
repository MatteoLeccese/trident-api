<?php

declare(strict_types=1);

namespace Src\Game\Domain\Repository;

use Src\Game\Domain\Model\Game;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;

/**
 * The system's only repository. `backend.md` warns against half-mixing
 * repositories and loose Eloquent: with a single aggregate, full adoption fits
 * in one file.
 *
 * Seats do NOT have a repository of their own: they are always loaded and saved
 * through their game, and no route addresses them separately.
 */
interface GameRepository
{
    public function find(GameId $id): ?Game;

    public function findByJoinCode(JoinCode $code): ?Game;

    /**
     * Saves the aggregate and empties its pending move log.
     *
     * The implementation is responsible for making the row, the seats and the
     * moves go in within the same transaction: the old system wrote the game, the
     * players and the cache separately and without a transaction.
     */
    public function save(Game $game): void;
}
