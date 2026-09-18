<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TilePool;

/**
 * What a rule is told about one draw.
 *
 * It carries both the position and the tile: the position is what the phone
 * touched, the tile is what the server found when it turned it over. A rule may
 * decide with either — "the last square left" is as much a rule as "the double
 * three".
 *
 * `seat` is filled by the aggregate from the current seat and never comes from
 * the request (TR-11), so there is no seat in this DTO but the drawer's.
 *
 * `poolBefore` is the pool as it stood before this position was marked, so a
 * rule that asks whether the stage has just run out reads it there and no rule
 * has to count draws (TR-54).
 */
final class DrawContext
{
    private function __construct(
        private readonly SeatNumber $seat,
        private readonly PoolPosition $position,
        private readonly Tile $tile,
        private readonly SeatRoster $seats,
        private readonly DrawLog $priorDraws,
        private readonly TilePool $poolBefore,
        private readonly int $turnNumber,
        private readonly StageId $stage,
        private readonly RuleState $state,
        private readonly RoomConfig $roomConfig,
    ) {}

    public static function of(
        SeatNumber $seat,
        PoolPosition $position,
        Tile $tile,
        SeatRoster $seats,
        DrawLog $priorDraws,
        TilePool $poolBefore,
        int $turnNumber,
        StageId $stage,
        RuleState $state,
        RoomConfig $roomConfig,
    ): self {
        if ($turnNumber < 1) {
            throw new InvalidArgumentException("A draw happens on turn 1 or later, got {$turnNumber}.");
        }

        return new self(
            $seat,
            $position,
            $tile,
            $seats,
            $priorDraws,
            $poolBefore,
            $turnNumber,
            $stage,
            $state,
            $roomConfig,
        );
    }

    public function seat(): SeatNumber
    {
        return $this->seat;
    }

    public function position(): PoolPosition
    {
        return $this->position;
    }

    public function tile(): Tile
    {
        return $this->tile;
    }

    public function seats(): SeatRoster
    {
        return $this->seats;
    }

    /** The history before this draw: it does not contain this one. */
    public function priorDraws(): DrawLog
    {
        return $this->priorDraws;
    }

    public function poolBefore(): TilePool
    {
        return $this->poolBefore;
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
