<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

/**
 * One entry of the `DrawLog`: a seat, the position it touched and the tile that
 * came up, in a stage.
 *
 * It carries no seat for anybody but the drawer: a draw is attributed to the
 * `current_seat` the aggregate already knows, and the phone never sends a seat
 * (TR-11).
 *
 * The stage is an opaque string owned by the RuleSet. Nothing here branches on
 * its value.
 */
final class Draw
{
    private function __construct(
        private readonly string $stage,
        private readonly SeatNumber $seat,
        private readonly PoolPosition $position,
        private readonly Tile $tile,
    ) {}

    public static function of(string $stage, SeatNumber $seat, PoolPosition $position, Tile $tile): self
    {
        return new self($stage, $seat, $position, $tile);
    }

    public function stage(): string
    {
        return $this->stage;
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
}
