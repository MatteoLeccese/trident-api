<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * Turn rotation around the table. Pure, and independent of the game's rules: the
 * RuleSet can skip the next turn with `overrideNextSeat`, but the base order is
 * this one.
 *
 * The old backend had no implementation of this at all.
 */
final class SeatRing
{
    public static function next(SeatNumber $from, int $seatCount): SeatNumber
    {
        return SeatNumber::fromInt($from->value() % $seatCount + 1);
    }
}
