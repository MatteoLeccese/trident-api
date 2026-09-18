<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\RenameSeat;

use Src\Game\Application\Service\GameWriter;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Domain\Service\Clock;

final class RenameSeatHandler
{
    public function __construct(
        private readonly GameWriter $writer,
        private readonly Clock $clock,
    ) {}

    public function handle(RenameSeatCommand $command): GameSnapshot
    {
        return $this->writer->write(
            $command->gameId,
            $command->expectedVersion,
            $command->requestId,
            MoveKind::SEAT_RENAMED,
            function (Game $game) use ($command): void {
                // The roster validates before anything is touched: if it throws,
                // the game is left exactly as it was.
                $game->renameSeat(
                    SeatNumber::fromInt($command->seat),
                    Nickname::fromString($command->nickname),
                    $this->clock,
                );
            },
        );
    }
}
