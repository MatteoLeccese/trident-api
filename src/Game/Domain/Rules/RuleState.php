<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;

/**
 * The blob a ruleset keeps between turns, stored in `games.rule_state` jsonb.
 *
 * Opaque: the framework carries it, versions it and hands it back, and never
 * reads a key of it. `trident.v1` keeps nothing beyond the version (TR-19).
 *
 * It always carries `_v = RuleSet::stateVersion()`, stamped when the state is
 * created and never moved afterwards. A version gap has exactly one outcome: a
 * ruleset that reads a state written by another version of itself ends the game
 * with `FinishReason::RULESET_UPGRADED` rather than misreading it quietly. No
 * member of `Outcome` carries a restamped state, and none may be added without
 * the framework member that applies it — a version the framework moved on its
 * own is the silent upgrade the `_v` guard exists to prevent.
 *
 * `games.room_config` deliberately carries no such version and never ends a
 * game: see documentation/conventions/room-config.md.
 *
 * Immutable: `with()` returns the next state.
 */
final class RuleState
{
    /** The one key the framework owns inside an otherwise opaque blob. */
    public const VERSION_KEY = '_v';

    /**
     * @param  array<string, mixed>  $values  without the version key
     */
    private function __construct(private readonly int $version, private readonly array $values) {}

    public static function initial(int $stateVersion): self
    {
        return new self(self::assertVersion($stateVersion), []);
    }

    /**
     * Reconstruction from persistence. The repository uses it; nothing else.
     *
     * A stored state with no usable `_v` is refused rather than read as version
     * one: the column is only ever written by `toArray()`, which always stamps
     * the version, so a blob without it is corruption and corruption must not be
     * mistaken for a state a ruleset can act on.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $version = $stored[self::VERSION_KEY] ?? null;

        if (! is_int($version)) {
            throw new InvalidArgumentException('A stored rule state must carry an integer '.self::VERSION_KEY.'.');
        }

        unset($stored[self::VERSION_KEY]);

        return new self(self::assertVersion($version), $stored);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function isAtVersion(int $stateVersion): bool
    {
        return $this->version === $stateVersion;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Applies an `Outcome`'s patch: the keys it names are overwritten and every
     * other key is left alone. A patch never removes a key and never touches the
     * version: the version of a state is the one that wrote it.
     *
     * @param  array<string, mixed>  $patch
     */
    public function with(array $patch): self
    {
        self::assertPatch($patch);

        return new self($this->version, [...$this->values, ...$patch]);
    }

    /**
     * The blob as it is stored, version first.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [self::VERSION_KEY => $this->version, ...$this->values];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public static function assertPatch(array $patch): void
    {
        if (array_key_exists(self::VERSION_KEY, $patch)) {
            throw new InvalidArgumentException(
                'A rule state patch cannot carry '.self::VERSION_KEY.': the framework stamps the version.',
            );
        }

        foreach (array_keys($patch) as $key) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('A rule state patch is keyed by non-empty strings.');
            }
        }
    }

    private static function assertVersion(int $stateVersion): int
    {
        if ($stateVersion < 1) {
            throw new InvalidArgumentException("A state version is 1 or greater, got {$stateVersion}.");
        }

        return $stateVersion;
    }
}
