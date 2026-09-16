<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

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
                'Una partida necesita entre %d y %d jugadores, y se han enviado %d.',
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

        throw new SeatNotFoundException("El asiento {$number->value()} no existe en esta partida.");
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
            throw new SeatNotFoundException("El asiento {$number->value()} no existe en esta partida.");
        }

        foreach ($this->seats as $seat) {
            // Renaming yourself (changing capitalisation) is not a clash.
            if (! $seat->number()->equals($number) && $seat->nickname()->equals($nickname)) {
                throw new NicknameTakenException("El nombre '{$nickname->value()}' ya está en uso en esta partida.");
            }
        }

        $renamed = [];

        foreach ($this->seats as $seat) {
            $renamed[] = $seat->number()->equals($number) ? $seat->renamedTo($nickname) : $seat;
        }

        return new self($renamed);
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
                throw new NicknameTakenException("El nombre '{$nickname->value()}' está repetido.");
            }

            $seen[$key] = true;
        }
    }
}
