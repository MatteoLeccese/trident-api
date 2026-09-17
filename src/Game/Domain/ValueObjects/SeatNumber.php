<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A player's place at the table. **1..N, everywhere, forever.**
 *
 * The old system had four different index conventions — the creation route, the
 * cache, the frontend store and the DTO — and none of them matched another. This
 * class exists so that that question never has to be asked again.
 *
 * 1-based on purpose: an index of 0 is *falsy* when crossing over to JavaScript
 * and gets lost in any `seat || fallback`.
 */
final class SeatNumber implements JsonSerializable
{
    private function __construct(private readonly int $value) {}

    public static function first(): self
    {
        return new self(1);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidArgumentException("The seat must be 1 or higher, got {$value}.");
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }
}
