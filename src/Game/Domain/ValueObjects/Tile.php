<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A domino: two faces, each 0-6.
 *
 * Its identity is the two-character string of its faces, left then right, so
 * '36' is a three and a six. The order matters: '36' and '63' are different
 * tiles.
 */
final class Tile implements JsonSerializable, Stringable
{
    public const MAX_PIPS = 6;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        // \z rather than $: in PCRE, $ also matches just before a trailing newline.
        if (preg_match('/\A[0-'.self::MAX_PIPS.']{2}\z/', $value) !== 1) {
            throw new InvalidArgumentException("'{$value}' is not a domino.");
        }

        return new self($value);
    }

    public static function of(int $left, int $right): self
    {
        return self::fromString((string) $left.(string) $right);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function left(): int
    {
        return (int) $this->value[0];
    }

    public function right(): int
    {
        return (int) $this->value[1];
    }

    public function total(): int
    {
        return $this->left() + $this->right();
    }

    public function isDouble(): bool
    {
        return $this->left() === $this->right();
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
