<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\TilePool;

/**
 * What a rule is told when the seat it stopped the game for has answered.
 *
 * The third and last execution entry point of the seam, and the only one that is
 * not driven by a position being turned over. It exists because a
 * `PendingChoice` that nothing reads back would park a game forever.
 *
 * `seat` is the seat the choice named, which the framework checks against the
 * pending choice before it ever builds this: a rule is never handed an answer
 * from somebody the choice did not ask. `option` is one of `choice->options()`,
 * checked here as well, so a ruleset may branch on it with a `match` whose
 * default is an incident rather than a guess.
 *
 * `pool` is the stage's pool as it stands, with the draw that raised the choice
 * already applied — unlike `DrawContext::poolBefore()`, because by now the
 * position has been taken.
 */
final class ChoiceContext
{
    private function __construct(
        private readonly SeatNumber $seat,
        private readonly string $option,
        private readonly PendingChoice $choice,
        private readonly SeatRoster $seats,
        private readonly DrawLog $priorDraws,
        private readonly TilePool $pool,
        private readonly int $turnNumber,
        private readonly StageId $stage,
        private readonly RuleState $state,
        private readonly RoomConfig $roomConfig,
    ) {}

    /** `turnNumber` is 0 when the choice was raised before the first draw. */
    public static function of(
        SeatNumber $seat,
        string $option,
        PendingChoice $choice,
        SeatRoster $seats,
        DrawLog $priorDraws,
        TilePool $pool,
        int $turnNumber,
        StageId $stage,
        RuleState $state,
        RoomConfig $roomConfig,
    ): self {
        if (! $seat->equals($choice->seat())) {
            throw new InvalidArgumentException('That answer comes from a seat the choice did not ask.');
        }

        if (! $choice->allows($option)) {
            throw new InvalidArgumentException('That answer is not one of the options the choice offered.');
        }

        if ($turnNumber < 0) {
            throw new InvalidArgumentException("A turn number is 0 or greater, got {$turnNumber}.");
        }

        return new self($seat, $option, $choice, $seats, $priorDraws, $pool, $turnNumber, $stage, $state, $roomConfig);
    }

    public function seat(): SeatNumber
    {
        return $this->seat;
    }

    /** One of `choice()->options()`, and never anything else. */
    public function option(): string
    {
        return $this->option;
    }

    public function choice(): PendingChoice
    {
        return $this->choice;
    }

    public function seats(): SeatRoster
    {
        return $this->seats;
    }

    public function priorDraws(): DrawLog
    {
        return $this->priorDraws;
    }

    /** The pool with the draw that raised this choice already applied. */
    public function pool(): TilePool
    {
        return $this->pool;
    }

    public function turnNumber(): int
    {
        return $this->turnNumber;
    }

    public function stage(): StageId
    {
        return $this->stage;
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
