<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use JsonSerializable;
use Src\Shared\Domain\ValueObjects\Uuid;
use Stringable;

/**
 * A seat's identity, stable for the whole life of the game.
 *
 * A type of its own and not a bare `Uuid`, so a `GameId` cannot be handed to
 * something that expects a seat. It is assigned by the domain before saving and
 * never travels in the snapshot: it is a storage key, not part of the state the
 * phone and the television read.
 */
final class SeatId implements JsonSerializable, Stringable
{
    private function __construct(private readonly Uuid $uuid) {}

    public static function random(): self
    {
        return new self(Uuid::random());
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value));
    }

    public function value(): string
    {
        return $this->uuid->value();
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }

    public function jsonSerialize(): string
    {
        return $this->value();
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
