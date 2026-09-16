<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Model;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Model\SeatRing;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * The turn rotation. The old backend had no implementation of this at all.
 */
final class SeatRingTest extends TestCase
{
    public function test_the_turn_moves_to_the_next_seat(): void
    {
        $this->assertSame(2, SeatRing::next(SeatNumber::fromInt(1), 5)->value());
        $this->assertSame(5, SeatRing::next(SeatNumber::fromInt(4), 5)->value());
    }

    public function test_the_last_seat_hands_the_turn_back_to_the_first(): void
    {
        $this->assertSame(1, SeatRing::next(SeatNumber::fromInt(5), 5)->value());
    }

    public function test_it_wraps_correctly_at_every_table_size(): void
    {
        foreach ([3, 7, 15] as $seats) {
            $this->assertSame(1, SeatRing::next(SeatNumber::fromInt($seats), $seats)->value());

            // Going all the way round returns to whoever started.
            $current = SeatNumber::fromInt(1);

            for ($i = 0; $i < $seats; $i++) {
                $current = SeatRing::next($current, $seats);
            }

            $this->assertSame(1, $current->value(), "Con {$seats} asientos la vuelta no cierra.");
        }
    }

    public function test_a_table_of_three_cycles_one_two_three(): void
    {
        $visited = [];
        $current = SeatNumber::first();

        for ($i = 0; $i < 6; $i++) {
            $visited[] = $current->value();
            $current = SeatRing::next($current, 3);
        }

        $this->assertSame([1, 2, 3, 1, 2, 3], $visited);
    }
}
