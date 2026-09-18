<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;

/**
 * What the table chose before starting, stored in `games.room_config` jsonb and
 * frozen when play begins.
 *
 * **A flat map of dotted keys to scalar values. Never nested objects.** The same
 * literal string is the key of the spec, of the stored blob, of the projected
 * configuration and of the `config_key` inside an `Effect` (TR-42). Flatness is
 * what lets a generic validator work without understanding a hierarchy a ruleset
 * invented, and understanding a ruleset's hierarchy is knowing a rule.
 *
 * A setting cannot change the outcome of a game; that is the whole test. If
 * changing it changes who the trident is or when a stage ends, it is a rule and
 * it lives behind `RuleSet`.
 *
 * It carries no version and never ends a game, unlike `RuleState`: people write
 * it, every key has an independent default, so a missing key is filled and an
 * undeclared one is ignored. It is public by construction — it is painted on a
 * television — so it may never carry anything resembling a credential.
 */
final class RoomConfig implements JsonSerializable
{
    /** Flat and dotted: segments of lowercase letters, digits and underscores. */
    private const KEY_PATTERN = '/\A[a-z0-9_]+(\.[a-z0-9_]+)*\z/';

    /**
     * @param  array<string, string|int|float|bool>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! self::isValidKey($key)) {
                throw new InvalidArgumentException("'{$key}' is not a room configuration key.");
            }

            if (! is_scalar($value)) {
                throw new InvalidArgumentException("The key '{$key}' holds something that is not a scalar.");
            }
        }

        return new self($values);
    }

    /**
     * The tolerant reader: every key the spec declares, in declaration order,
     * taking the stored value when the field accepts it and the declared default
     * otherwise.
     *
     * A key the spec no longer declares is ignored here and is **not** dropped
     * from storage: a live person can be corrected, a saved row is history and is
     * not rewritten.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function resolve(array $stored, RoomConfigSpec $spec): self
    {
        $values = [];

        foreach ($spec->fields() as $field) {
            $value = $stored[$field->key()] ?? null;

            $values[$field->key()] = $field->accepts($value) ? $value : $field->default();
        }

        return new self($values);
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, string|int|float|bool|null $default = null): string|int|float|bool|null
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
