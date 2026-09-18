<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Model;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Exceptions\InvalidSeatOrderException;
use Src\Game\Domain\Exceptions\NicknameTakenException;
use Src\Game\Domain\Exceptions\RosterSizeException;
use Src\Game\Domain\Exceptions\SeatNotFoundException;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;

final class SeatRosterTest extends TestCase
{
    /**
     * @param  list<string>  $names
     */
    private function roster(array $names): SeatRoster
    {
        return SeatRoster::fromNicknames(array_map(Nickname::fromString(...), $names));
    }

    public function test_it_seats_people_in_the_order_they_were_added(): void
    {
        $roster = $this->roster(['Ana', 'Bea', 'Caro']);

        $this->assertSame('Ana', $roster->at(SeatNumber::fromInt(1))->nickname()->value());
        $this->assertSame('Bea', $roster->at(SeatNumber::fromInt(2))->nickname()->value());
        $this->assertSame('Caro', $roster->at(SeatNumber::fromInt(3))->nickname()->value());
    }

    public function test_seat_numbers_are_contiguous_from_one(): void
    {
        $numbers = array_map(
            static fn ($seat): int => $seat->number()->value(),
            $this->roster(['Ana', 'Bea', 'Caro', 'Dani'])->seats(),
        );

        $this->assertSame([1, 2, 3, 4], $numbers);
    }

    public function test_a_table_needs_at_least_three_people(): void
    {
        $this->expectException(RosterSizeException::class);

        $this->roster(['Ana', 'Bea']);
    }

    public function test_a_table_holds_at_most_fifteen(): void
    {
        $this->expectException(RosterSizeException::class);

        $this->roster(array_map(static fn (int $i): string => "Player{$i}", range(1, 16)));
    }

    public function test_fifteen_is_allowed_and_three_is_allowed(): void
    {
        $this->assertCount(3, $this->roster(['Ana', 'Bea', 'Caro'])->seats());
        $this->assertCount(15, $this->roster(array_map(
            static fn (int $i): string => "Player{$i}",
            range(1, 15),
        ))->seats());
    }

    public function test_two_people_cannot_share_a_name(): void
    {
        $this->expectException(NicknameTakenException::class);

        $this->roster(['Ana', 'Bea', 'Ana']);
    }

    public function test_names_collide_regardless_of_case(): void
    {
        // "ana" and "Ana" at the same table is guaranteed confusion.
        $this->expectException(NicknameTakenException::class);

        $this->roster(['Ana', 'Bea', 'ana']);
    }

    public function test_renaming_returns_a_new_roster_and_leaves_the_old_one_alone(): void
    {
        $original = $this->roster(['Ana', 'Bea', 'Caro']);

        $renamed = $original->rename(SeatNumber::fromInt(2), Nickname::fromString('Bea María'));

        $this->assertSame('Bea', $original->at(SeatNumber::fromInt(2))->nickname()->value());
        $this->assertSame('Bea María', $renamed->at(SeatNumber::fromInt(2))->nickname()->value());
    }

    public function test_renaming_to_a_name_someone_else_has_is_rejected(): void
    {
        $this->expectException(NicknameTakenException::class);

        $this->roster(['Ana', 'Bea', 'Caro'])->rename(SeatNumber::fromInt(2), Nickname::fromString('Ana'));
    }

    public function test_renaming_a_seat_to_its_own_name_is_allowed(): void
    {
        // Changing capitalisation without changing person cannot collide with itself.
        $renamed = $this->roster(['Ana', 'Bea', 'Caro'])
            ->rename(SeatNumber::fromInt(1), Nickname::fromString('ANA'));

        $this->assertSame('ANA', $renamed->at(SeatNumber::fromInt(1))->nickname()->value());
    }

    public function test_renaming_a_seat_that_is_not_at_the_table_fails(): void
    {
        $this->expectException(SeatNotFoundException::class);

        $this->roster(['Ana', 'Bea', 'Caro'])->rename(SeatNumber::fromInt(9), Nickname::fromString('Zoe'));
    }

    public function test_reordering_renumbers_by_absolute_permutation(): void
    {
        // The list is the arrangement that is wanted: the seat numbered 3 today
        // becomes seat 1, the seat numbered 1 becomes seat 2, and so on.
        $reordered = $this->roster(['Ana', 'Bea', 'Caro'])->reorder($this->order([3, 1, 2]));

        $this->assertSame(['Caro', 'Ana', 'Bea'], array_column($reordered->toArray(), 'nickname'));
        $this->assertSame([1, 2, 3], array_column($reordered->toArray(), 'seat'));
    }

    public function test_reordering_leaves_the_roster_it_was_asked_of_untouched(): void
    {
        $roster = $this->roster(['Ana', 'Bea', 'Caro']);

        $roster->reorder($this->order([3, 2, 1]));

        $this->assertSame(['Ana', 'Bea', 'Caro'], array_column($roster->toArray(), 'nickname'));
    }

    public function test_reordering_carries_each_seat_its_identity_and_its_roles(): void
    {
        $roster = SeatRoster::fromSeats([
            Seat::of(SeatNumber::fromInt(1), Nickname::fromString('Ana'), ['marked']),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Bea')),
            Seat::of(SeatNumber::fromInt(3), Nickname::fromString('Caro')),
        ]);

        $moved = $roster->reorder($this->order([2, 3, 1]))->at(SeatNumber::fromInt(3));

        $this->assertSame($roster->at(SeatNumber::first())->id()->value(), $moved->id()->value());
        $this->assertSame(['marked'], $moved->roles());
    }

    public function test_an_order_that_does_not_name_every_seat_is_refused(): void
    {
        $this->expectException(InvalidSeatOrderException::class);

        $this->roster(['Ana', 'Bea', 'Caro'])->reorder($this->order([1, 2]));
    }

    public function test_an_order_that_names_a_seat_twice_is_refused(): void
    {
        // It would leave one number unassigned and another claimed twice, which
        // is the one way an absolute permutation can break contiguity.
        $this->expectException(InvalidSeatOrderException::class);

        $this->roster(['Ana', 'Bea', 'Caro'])->reorder($this->order([1, 1, 2]));
    }

    public function test_an_order_that_names_a_seat_nobody_holds_is_refused(): void
    {
        $this->expectException(SeatNotFoundException::class);

        $this->roster(['Ana', 'Bea', 'Caro'])->reorder($this->order([1, 2, 9]));
    }

    /**
     * @param  list<int>  $numbers
     * @return list<SeatNumber>
     */
    private function order(array $numbers): array
    {
        return array_map(SeatNumber::fromInt(...), $numbers);
    }

    public function test_asking_for_a_seat_that_does_not_exist_fails(): void
    {
        $this->expectException(SeatNotFoundException::class);

        $this->roster(['Ana', 'Bea', 'Caro'])->at(SeatNumber::fromInt(4));
    }
}
