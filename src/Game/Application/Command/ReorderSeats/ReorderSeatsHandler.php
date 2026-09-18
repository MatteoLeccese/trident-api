<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\ReorderSeats;

use Src\Game\Application\Service\GameWriter;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Domain\Service\Clock;

/**
 * The table renumbered, which only the lobby accepts: `game_moves.actor_seat` is
 * a bare number and not a reference to a person, so a seat renumbered once there
 * is history would make that history name somebody else.
 *
 * The payload is the **absolute permutation** and not a "move 3 to 1": a drag
 * emits one intention and many frames, and only an intention that names the
 * arrangement it wants stays true when a frame is repeated. That payload is not
 * idempotent by itself — applying `[3, 1, 2]` twice is not applying it once — so
 * what makes a repeat safe is the write protocol around it: `expected_version`,
 * which a repeat no longer matches, and `X-Request-Id`, which answers the second
 * attempt with the result of the first.
 */
final class ReorderSeatsHandler
{
    public function __construct(
        private readonly GameWriter $writer,
        private readonly Clock $clock,
    ) {}

    public function handle(ReorderSeatsCommand $command): GameSnapshot
    {
        return $this->writer->write(
            $command->gameId,
            $command->expectedVersion,
            $command->requestId,
            MoveKind::SEATS_REORDERED,
            function (Game $game) use ($command): void {
                // The roster validates the permutation — every seat of the table,
                // exactly once — and refuses it before anything is touched.
                $game->reorderSeats(array_map(SeatNumber::fromInt(...), $command->order), $this->clock);
            },
        );
    }
}
