<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Monotonic counter of an aggregate's state.
 *
 * The client relies on it going up one at a time: `incoming === current + 1`
 * means "apply"; a bigger jump means "there is a gap, resynchronise".
 */
final class Version implements JsonSerializable
{
    private function __construct(private readonly int $value) {}

    public static function initial(): self
    {
        return new self(1);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidArgumentException("La versión debe ser 1 o mayor, recibida {$value}.");
        }

        return new self($value);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function value(): int
    {
        return $this->value;
    }

    /**
     * The client's guard compares `incoming.version` against a number. If this
     * travelled as `{}`, the television would stay resynchronising forever.
     */
    public function jsonSerialize(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }
}
