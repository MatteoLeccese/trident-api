<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use JsonSerializable;
use Src\Game\Domain\Exceptions\PoolPositionNotInPoolException;

/**
 * A 1-based index into a `TilePool`.
 *
 * It is what a draw addresses and what the draw route carries, so a tile is
 * never named by its face: the same tile legitimately exists in both stages
 * (TR-04), and the phone cannot name a face it has not seen.
 *
 * Both failure modes of that route are 4xx, on two different paths. A
 * non-numeric segment is a 404 from the route constraint and never reaches this
 * class; a numeric one always does, so an out-of-range value throws a
 * `DomainException` that renders as 422 `pool_position_not_in_pool` — an
 * `InvalidArgumentException` here would render as a 500 for input a client is
 * entitled to get wrong.
 *
 * Only the lower bound lives here. The upper bound is the pool's own size, which
 * `TilePool::has()` answers, because the size of a deck is a rule the RuleSet
 * owns and a ceiling constant in this class would be the framework trimming it.
 */
final class PoolPosition implements JsonSerializable
{
    public const FIRST = 1;

    private function __construct(private readonly int $value) {}

    public static function first(): self
    {
        return new self(self::FIRST);
    }

    public static function fromInt(int $value): self
    {
        if ($value < self::FIRST) {
            throw new PoolPositionNotInPoolException("Position {$value} is not a pool position.");
        }

        return new self($value);
    }

    /**
     * Reads the position as the route hands it over: digits, in canonical form.
     *
     * The length check is not cosmetic. `(int) '99999999999999999999'` saturates
     * to PHP_INT_MAX in silence, so a segment of twenty digits would arrive as a
     * plausible position instead of a rejection.
     */
    public static function fromString(string $value): self
    {
        // \z rather than $: in PCRE, $ also matches just before a trailing newline.
        if (preg_match('/\A[0-9]+\z/', $value) !== 1 || $value !== (string) (int) $value) {
            throw new PoolPositionNotInPoolException;
        }

        return self::fromInt((int) $value);
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
