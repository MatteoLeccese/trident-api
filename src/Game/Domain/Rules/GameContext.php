<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * What a rule is told about a game when nobody is drawing.
 *
 * A purpose-built DTO, and neither the snapshot nor the aggregate. The snapshot
 * is the one class whose job is to be safe to broadcast, and it does not carry
 * the faces nobody has taken (TR-07), so a rule that needs them could not read
 * it; passing the aggregate would make the real surface of the seam the whole
 * model, which is the same as having no seam.
 *
 * `seats` is the roster, which is what carries each seat's roles: a role is read
 * from the seats (TR-29), and there is no `tridentSeat` field here, because a
 * dedicated field would be a rule baked into the shape of the seam. Rotation is
 * `SeatRing::next()` over `seats->count()`.
 *
 * `currentSeat` is nullable because `onGameStarted` runs before a cursor exists
 * (TR-17); a non-nullable type would force inventing a seat zero for the one
 * moment when there is none.
 *
 * `priorDraws` travels even here: `deck()` and `visibility()` are the two places
 * a rule may depend on what has already come up, and without it they would have
 * to invent a stage in order to remember.
 */
final class GameContext
{
    private function __construct(
        private readonly StageId $stage,
        private readonly SeatRoster $seats,
        private readonly ?SeatNumber $currentSeat,
        private readonly DrawLog $priorDraws,
        private readonly int $turnNumber,
        private readonly RuleState $state,
        private readonly RoomConfig $roomConfig,
    ) {}

    /** `turnNumber` is 0 before the first turn of the game. */
    public static function of(
        StageId $stage,
        SeatRoster $seats,
        ?SeatNumber $currentSeat,
        DrawLog $priorDraws,
        int $turnNumber,
        RuleState $state,
        RoomConfig $roomConfig,
    ): self {
        if ($turnNumber < 0) {
            throw new InvalidArgumentException("A turn number is 0 or greater, got {$turnNumber}.");
        }

        return new self($stage, $seats, $currentSeat, $priorDraws, $turnNumber, $state, $roomConfig);
    }

    public function stage(): StageId
    {
        return $this->stage;
    }

    public function seats(): SeatRoster
    {
        return $this->seats;
    }

    public function currentSeat(): ?SeatNumber
    {
        return $this->currentSeat;
    }

    public function priorDraws(): DrawLog
    {
        return $this->priorDraws;
    }

    public function turnNumber(): int
    {
        return $this->turnNumber;
    }

    public function state(): RuleState
    {
        return $this->state;
    }

    public function roomConfig(): RoomConfig
    {
        return $this->roomConfig;
    }
}
