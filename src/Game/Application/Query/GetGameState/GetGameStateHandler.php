<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\GetGameState;

use InvalidArgumentException;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\GameId;

/**
 * The read that doubles as resynchronisation: it is the SAME shape that is emitted
 * over the socket, so recovery is the same code path as the initial load and gets
 * exercised on every page open.
 */
final class GetGameStateHandler
{
    public function __construct(private readonly GameRepository $games) {}

    public function handle(GetGameStateQuery $query): GameSnapshot
    {
        try {
            $id = GameId::fromString($query->gameId);
        } catch (InvalidArgumentException) {
            throw new GameNotFoundException;
        }

        $game = $this->games->find($id) ?? throw new GameNotFoundException;

        return $game->snapshot();
    }
}
