<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use Closure;
use InvalidArgumentException;

/**
 * The write credential: whoever holds it runs the game.
 *
 * It lives **only** in an httpOnly cookie set by the Next BFF. The browser never
 * sees it, and only its hash ever leaves the database.
 *
 * This class is designed to be hard to leak: it is not `Stringable`, it is not
 * `JsonSerializable`, and its `__debugInfo` is redacted. A token in a log or in
 * an error message is a stolen token.
 *
 * See documentation/conventions/credential-model.md.
 */
final class ControllerToken
{
    private const BYTES = 32;

    private const ENCODED_LENGTH = 43;

    /**
     * The value lives inside a closure and not in a property.
     *
     * This is not decorative paranoia: with a private property, `var_export()`
     * and `serialize()` expose it just the same, and `__debugInfo` does not cover
     * them. This way print_r, var_dump, var_export and json_encode stay clean,
     * and serialize() throws outright — the token cannot end up in a session or
     * in the cache.
     */
    private readonly Closure $reveal;

    private function __construct(string $value)
    {
        $this->reveal = static fn (): string => $value;
    }

    public static function generate(): self
    {
        return new self(self::encode(random_bytes(self::BYTES)));
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/\A[A-Za-z0-9_-]{'.self::ENCODED_LENGTH.'}\z/', $value) !== 1) {
            throw new InvalidArgumentException('The controller token is not in the expected shape.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return ($this->reveal)();
    }

    /** The only thing that is persisted. */
    public function hash(): string
    {
        return hash('sha256', $this->value());
    }

    /** Constant-time comparison against the stored hash. */
    public function matchesHash(string $storedHash): bool
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $storedHash) !== 1) {
            return false;
        }

        return hash_equals($storedHash, $this->hash());
    }

    /**
     * What print_r, var_dump and var_export see.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
