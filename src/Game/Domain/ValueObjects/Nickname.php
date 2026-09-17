<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * The name someone appears under on the television.
 *
 * Limits 2..24 characters, changed on purpose from the inherited 4..40: "Bo" and
 * "Al" are real names that the old minimum rejected, and fifteen names of forty
 * characters is a television layout nobody has solved.
 * See documentation/conventions/waived-golden-rules.md.
 *
 * The domain is the authority over these numbers; `config/trident.php` mirrors
 * them for the frontend and a test stops the two from drifting apart.
 */
final class Nickname implements JsonSerializable, Stringable
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 24;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        // Control characters are rejected before normalising: a line break inside
        // a name breaks any layout and is not an oversight.
        if (preg_match('/[\p{C}]/u', $value) === 1) {
            throw new InvalidArgumentException('The name cannot contain control characters.');
        }

        // Repeated spaces collapse: two names that look the same on a television
        // must be the same name.
        $normalised = trim((string) preg_replace('/\s+/u', ' ', $value));
        $length = mb_strlen($normalised);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('The name must be between %d and %d characters.', self::MIN_LENGTH, self::MAX_LENGTH),
            );
        }

        return new self($normalised);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * Comparison key for case-insensitive uniqueness.
     *
     * **It is persisted as a column of its own (`game_seats.nickname_key`)**, and
     * not as a functional index over `lower(nickname)`. Two verified reasons: a
     * functional index is not portable — in Postgres it compiles to a UNIQUE
     * constraint, which only accepts column names — and, even if it were, SQLite's
     * `lower()` only folds ASCII, so 'JOSÉ' and 'josé' would collide in
     * production but not in the tests. The authority is this method.
     */
    public function comparisonKey(): string
    {
        return mb_strtolower($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->comparisonKey() === $other->comparisonKey();
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
