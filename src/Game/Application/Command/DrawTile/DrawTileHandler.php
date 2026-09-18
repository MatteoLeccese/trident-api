<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\DrawTile;

use Src\Game\Application\Service\GameRules;
use Src\Game\Application\Service\GameWriter;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Shared\Domain\Service\Clock;

/**
 * One position turned over.
 *
 * **It carries no seat.** There is one phone and one `ControllerToken`, so the
 * server cannot know which human is holding it: the aggregate attributes the
 * draw to the current seat it already holds (TR-11), and `not_your_turn` does
 * not exist in this system.
 *
 * The position arrives as the string the route captured. Reading it here rather
 * than casting it in the controller is what keeps a non-canonical segment — a
 * leading zero, twenty digits that saturate to `PHP_INT_MAX` — a refusal instead
 * of a plausible position.
 */
final class DrawTileHandler
{
    public function __construct(
        private readonly GameWriter $writer,
        private readonly GameRules $rules,
        private readonly Clock $clock,
    ) {}

    public function handle(DrawTileCommand $command): GameSnapshot
    {
        return $this->writer->write(
            $command->gameId,
            $command->expectedVersion,
            $command->requestId,
            MoveKind::TILE_DRAWN,
            function (Game $game) use ($command): void {
                $game->drawTile(
                    PoolPosition::fromString($command->position),
                    $this->rules->of($game),
                    $this->clock,
                );
            },
        );
    }
}
