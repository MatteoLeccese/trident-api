<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use Closure;
use InvalidArgumentException;

/**
 * The shuffle's seed. **A server secret** (TR-09).
 *
 * It is not a credential and it still gets a credential's treatment, because
 * whoever holds it replays `SeededShuffle` and reads every face-down position of
 * both stages. Leaking it does not open a door: it ends the game, silently, with
 * nothing in any log to show for it. It leaves the server in no response body
 * and in no error body, and it is not accepted as a request field.
 *
 * Hence the same shape as `ControllerToken`: the value lives inside a closure and
 * not in a property, so print_r, var_dump, var_export and json_encode stay clean
 * and `serialize()` throws outright; the class is neither `Stringable` nor
 * `JsonSerializable`, so it cannot be interpolated into a log line or folded into
 * a payload by accident.
 *
 * Where it differs from `ControllerToken`: a token is stored as a hash and this
 * is stored raw in `games.shuffle_seed`, because a shuffle that cannot be
 * recomputed is a pool that cannot be read back. `value()` exists for that one
 * caller.
 */
final class Seed
{
    private const BYTES = 32;

    private const ENCODED_LENGTH = 43;

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
            throw new InvalidArgumentException('The seed is not in the expected shape.');
        }

        return new self($value);
    }

    /** Read by persistence and by the shuffle. Nothing else may call it. */
    public function value(): string
    {
        return ($this->reveal)();
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
