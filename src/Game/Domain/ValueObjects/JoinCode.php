<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * The code someone types into the television to watch a game.
 *
 * Crockford base32: no I, L, O or U. The first three are the classic misreadings
 * from the sofa, and U is excluded so that no words are formed. It is typed with
 * a television remote, so it cannot be a UUID.
 *
 * It is a **read-only** credential: it grants a view of the board of a drinking
 * game, nothing more. See documentation/conventions/credential-model.md.
 */
final class JoinCode implements JsonSerializable, Stringable
{
    public const LENGTH = 6;

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** What people type when they read the character next to it. */
    private const CONFUSIONS = ['O' => '0', 'I' => '1', 'L' => '1'];

    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return new self($code);
    }

    public static function fromString(string $value): self
    {
        $normalised = strtr(
            strtoupper((string) preg_replace('/[\s-]+/u', '', $value)),
            self::CONFUSIONS,
        );

        if (preg_match('/\A['.self::ALPHABET.']{'.self::LENGTH.'}\z/', $normalised) !== 1) {
            throw new InvalidArgumentException("'{$value}' is not a valid game code.");
        }

        return new self($normalised);
    }

    public function value(): string
    {
        return $this->value;
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
