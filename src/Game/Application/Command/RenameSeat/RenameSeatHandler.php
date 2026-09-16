<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\RenameSeat;

use InvalidArgumentException;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Service\StatePublisher;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Domain\Service\Clock;

final class RenameSeatHandler
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly StatePublisher $publisher,
        private readonly Clock $clock,
    ) {}

    public function handle(RenameSeatCommand $command): GameSnapshot
    {
        $game = $this->games->find($this->gameId($command->gameId))
            ?? throw new GameNotFoundException;

        // If the aggregate rejects, nothing has been saved or emitted.
        $game->renameSeat(
            SeatNumber::fromInt($command->seat),
            Nickname::fromString($command->nickname),
            $this->clock,
        );

        $this->games->save($game);
        $this->publisher->publish($game->snapshot());

        return $game->snapshot();
    }

    private function gameId(string $raw): GameId
    {
        try {
            return GameId::fromString($raw);
        } catch (InvalidArgumentException) {
            // A malformed id is a game that does not exist, not a 500.
            throw new GameNotFoundException;
        }
    }
}
