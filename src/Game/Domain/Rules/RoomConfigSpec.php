<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Every room setting a ruleset declares, in the order the lobby paints them.
 *
 * This is the whole surface the room configuration plugs into: a new setting is
 * one more `RoomConfigField` here, never a column, an endpoint or a branch in
 * the client. That is what keeps the zero-migration criterion of
 * documentation/conventions/rule-set-seam.md true.
 *
 * The two directions have opposite policies, and the asymmetry is deliberate:
 * the form is strict — an undeclared key or a malformed value is a 422 naming
 * the key, never a silent discard — while the reader is tolerant, filling a
 * missing key with its default so that a client may assume every declared key is
 * present. `RoomConfig::resolve()` is that reader.
 */
final class RoomConfigSpec implements JsonSerializable
{
    /**
     * @param  list<RoomConfigField>  $fields
     */
    private function __construct(private readonly array $fields) {}

    public static function of(RoomConfigField ...$fields): self
    {
        $seen = [];

        foreach ($fields as $field) {
            if (isset($seen[$field->key()])) {
                throw new InvalidArgumentException("The key '{$field->key()}' is declared twice.");
            }

            $seen[$field->key()] = true;
        }

        return new self(array_values($fields));
    }

    /**
     * @return list<RoomConfigField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (RoomConfigField $field): string => $field->key(), $this->fields);
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function field(string $key): RoomConfigField
    {
        foreach ($this->fields as $field) {
            if ($field->key() === $key) {
                return $field;
            }
        }

        throw new InvalidArgumentException("The key '{$key}' is not declared by this spec.");
    }

    /**
     * @return array<string, string|bool>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields as $field) {
            $defaults[$field->key()] = $field->default();
        }

        return $defaults;
    }

    /**
     * Submitted keys this spec does not declare. Each one is a 422 naming it:
     * somebody typed something and pressed save, and is entitled to know it was
     * not saved.
     *
     * @param  array<string, mixed>  $submitted
     * @return list<string>
     */
    public function unknownKeys(array $submitted): array
    {
        $unknown = [];

        foreach (array_keys($submitted) as $key) {
            if (! is_string($key) || ! $this->has($key)) {
                $unknown[] = (string) $key;
            }
        }

        sort($unknown);

        return $unknown;
    }

    /**
     * Submitted keys whose value this spec declares but does not accept. Absence
     * is not among them: a missing key is always legal and takes its default.
     *
     * @param  array<string, mixed>  $submitted
     * @return list<string>
     */
    public function invalidKeys(array $submitted): array
    {
        $invalid = [];

        foreach ($this->fields as $field) {
            if (array_key_exists($field->key(), $submitted) && ! $field->accepts($submitted[$field->key()])) {
                $invalid[] = $field->key();
            }
        }

        sort($invalid);

        return $invalid;
    }

    /**
     * What the lobby form is generated from. It travels inside the snapshot while
     * the game is in the lobby and disappears afterwards — hidden by state, never
     * by viewer.
     *
     * @return list<array{key: string, kind: string, label: string, default: string|bool, max_length: int|null, options: list<string>}>
     */
    public function toArray(): array
    {
        return array_map(static fn (RoomConfigField $field): array => $field->toArray(), $this->fields);
    }

    /**
     * @return list<array{key: string, kind: string, label: string, default: string|bool, max_length: int|null, options: list<string>}>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
