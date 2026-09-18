<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use Src\Game\Domain\Exceptions\InvalidSeatOrderException;
use Src\Game\Domain\Exceptions\NicknameTakenException;
use Src\Game\Domain\Exceptions\RosterSizeException;
use Src\Game\Domain\Exceptions\SeatNotFoundException;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * The table: contiguous seats 1..N with unique names.
 *
 * Immutable. The three invariants — size, contiguity and name uniqueness — live
 * here and nowhere else, which is exactly what the old system did not have: its
 * lobby rules were only in the browser form.
 */
final class SeatRoster
{
    public const MIN_SEATS = 3;

    public const MAX_SEATS = 15;

    /**
     * @param  list<Seat>  $seats
     */
    private function __construct(private readonly array $seats) {}

    /**
     * @param  list<Nickname>  $nicknames
     */
    public static function fromNicknames(array $nicknames): self
    {
        $count = count($nicknames);

        if ($count < self::MIN_SEATS || $count > self::MAX_SEATS) {
            throw new RosterSizeException(sprintf(
                'A game needs between %d and %d players, but %d were sent.',
                self::MIN_SEATS,
                self::MAX_SEATS,
                $count,
            ));
        }

        self::assertNicknamesAreUnique($nicknames);

        $seats = [];

        foreach (array_values($nicknames) as $index => $nickname) {
            $seats[] = Seat::of(SeatNumber::fromInt($index + 1), $nickname);
        }

        return new self($seats);
    }

    /**
     * @param  list<Seat>  $seats
     */
    public static function fromSeats(array $seats): self
    {
        return new self(array_values($seats));
    }

    /**
     * @return list<Seat>
     */
    public function seats(): array
    {
        return $this->seats;
    }

    public function count(): int
    {
        return count($this->seats);
    }

    public function at(SeatNumber $number): Seat
    {
        foreach ($this->seats as $seat) {
            if ($seat->number()->equals($number)) {
                return $seat;
            }
        }

        throw new SeatNotFoundException("Seat {$number->value()} is not in this game.");
    }

    public function has(SeatNumber $number): bool
    {
        foreach ($this->seats as $seat) {
            if ($seat->number()->equals($number)) {
                return true;
            }
        }

        return false;
    }

    public function rename(SeatNumber $number, Nickname $nickname): self
    {
        if (! $this->has($number)) {
            throw new SeatNotFoundException("Seat {$number->value()} is not in this game.");
        }

        foreach ($this->seats as $seat) {
            // Renaming yourself (changing capitalisation) is not a clash.
            if (! $seat->number()->equals($number) && $seat->nickname()->equals($nickname)) {
                throw new NicknameTakenException("The name '{$nickname->value()}' is already taken in this game.");
            }
        }

        $renamed = [];

        foreach ($this->seats as $seat) {
            $renamed[] = $seat->number()->equals($number) ? $seat->renamedTo($nickname) : $seat;
        }

        return new self($renamed);
    }

    /**
     * The same table in a new order, addressed by an **absolute permutation**:
     * the seat numbered `$order[0]` today becomes seat 1, `$order[1]` becomes
     * seat 2, and so on. A drag produces one intention and many frames, and an
     * intention that names the arrangement it wants is the one a repeated frame
     * cannot compound.
     *
     * The list has to name every seat of the table exactly once, so contiguity
     * and the set of names survive by construction and the three invariants of
     * this class are the same afterwards.
     *
     * @param  list<SeatNumber>  $order
     */
    public function reorder(array $order): self
    {
        $order = array_values($order);

        if (count($order) !== count($this->seats)) {
            throw new InvalidSeatOrderException(sprintf(
                'This table has %d seats and the order names %d.',
                count($this->seats),
                count($order),
            ));
        }

        $seen = [];
        $reordered = [];

        foreach ($order as $index => $number) {
            if (isset($seen[$number->value()])) {
                throw new InvalidSeatOrderException("Seat {$number->value()} is named twice in that order.");
            }

            $seen[$number->value()] = true;

            // `at()` refuses a number this table does not have, so an order that
            // names a seat nobody holds is rejected before anything is built.
            $reordered[] = $this->at($number)->renumberedTo(SeatNumber::fromInt($index + 1));
        }

        return new self($reordered);
    }

    /**
     * The roster with one seat carrying one more role, which is how the framework
     * applies `EffectKind::ASSIGN_ROLE` (TR-27). Idempotent, and the roster is
     * the only place a role is ever written: there is no `trident_seat` column
     * and no DTO of the seam carries one (TR-29).
     */
    public function assignRole(SeatNumber $number, string $role): self
    {
        if (! $this->has($number)) {
            throw new SeatNotFoundException("Seat {$number->value()} is not in this game.");
        }

        $assigned = [];

        foreach ($this->seats as $seat) {
            $assigned[] = $seat->number()->equals($number) ? $seat->withRole($role) : $seat;
        }

        return new self($assigned);
    }

    /**
     * @return list<array{seat: int, nickname: string, roles: list<string>}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Seat $seat): array => $seat->toArray(), $this->seats);
    }

    /**
     * @param  list<Nickname>  $nicknames
     */
    private static function assertNicknamesAreUnique(array $nicknames): void
    {
        $seen = [];

        foreach ($nicknames as $nickname) {
            $key = $nickname->comparisonKey();

            if (isset($seen[$key])) {
                throw new NicknameTakenException("The name '{$nickname->value()}' is repeated.");
            }

            $seen[$key] = true;
        }
    }
}
