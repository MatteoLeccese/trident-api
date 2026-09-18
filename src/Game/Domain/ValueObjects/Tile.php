<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A domino: two faces, each a single decimal digit.
 *
 * Its identity is the two-character string of its faces, left then right, so
 * '36' is a three and a six (TR-02). The order matters: '36' and '63' are
 * different tiles.
 *
 * The alphabet is the wire format's and not a game's: which faces are actually
 * dealt is decided by the deck a ruleset returns from `RuleSet::deck()`, the
 * same way the number of tiles is. A framework value object that stopped at six
 * would make the highest face of one ruleset a property of the framework.
 */
final class Tile implements JsonSerializable, Stringable
{
    public const MIN_FACE = 0;

    public const MAX_FACE = 9;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        // \z rather than $: in PCRE, $ also matches just before a trailing newline.
        if (preg_match('/\A['.self::MIN_FACE.'-'.self::MAX_FACE.']{2}\z/', $value) !== 1) {
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
