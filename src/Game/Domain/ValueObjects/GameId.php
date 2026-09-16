<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use JsonSerializable;
use Src\Shared\Domain\ValueObjects\Uuid;
use Stringable;

/**
 * A game's identity. It is also the **read-only** credential with which a
 * television opens the link, so it has to be unguessable: a v4 UUID, never a
 * counter.
 */
final class GameId implements JsonSerializable, Stringable
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
