<?php

declare(strict_types=1);

namespace Src\Game\Domain\Repository;

use Src\Game\Domain\Model\Game;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;

/**
 * The system's only repository: one aggregate, one place that reads and writes
 * it, and no loose Eloquent anywhere else.
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
     * The implementation puts the row, the seats and the moves in within the same
     * transaction: a state, its roster and its move log are never readable in
     * disagreement with each other.
     */
    public function save(Game $game): void;
}
