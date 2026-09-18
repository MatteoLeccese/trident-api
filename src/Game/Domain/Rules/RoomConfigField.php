<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One declared room setting: what it is called, what it accepts and what it is
 * worth when the table says nothing.
 *
 * The lobby form is generated from these, so the frontend renders the seven
 * challenge boxes of TR-43 without naming a challenge, and a setting that does
 * not exist yet arrives as one more entry in a list.
 *
 * The framework validates against this declaration without understanding it: no
 * ruleset writes a validator, and no class of the framework knows what a
 * challenge is.
 *
 * A declared default is never empty (TR-51). An empty value is a decision the
 * table makes, and `accepts()` allows it.
 */
final class RoomConfigField implements JsonSerializable
{
    /** Free text the table writes, bounded in length. */
    public const TEXT = 'text';

    /** A strict boolean: '1' and 'true' are refused, never coerced. */
    public const TOGGLE = 'toggle';

    /** One of a closed list of strings. */
    public const CHOICE = 'choice';

    public const KINDS = [self::TEXT, self::TOGGLE, self::CHOICE];

    /**
     * @param  list<string>  $options
     */
    private function __construct(
        private readonly string $kind,
        private readonly string $key,
        private readonly string $label,
        private readonly string|bool $default,
        private readonly ?int $maxLength,
        private readonly array $options,
    ) {}

    public static function text(string $key, string $label, string $default, int $maxLength): self
    {
        if ($maxLength < 1) {
            throw new InvalidArgumentException("The field '{$key}' needs a maximum length of 1 or more.");
        }

        $field = new self(self::TEXT, self::assertKey($key), self::assertLabel($key, $label), $default, $maxLength, []);

        return $field->assertDefault();
    }

    public static function toggle(string $key, string $label, bool $default): self
    {
        $field = new self(self::TOGGLE, self::assertKey($key), self::assertLabel($key, $label), $default, null, []);

        return $field->assertDefault();
    }

    /**
     * @param  list<string>  $options
     */
    public static function choice(string $key, string $label, array $options, string $default): self
    {
        $options = array_values($options);

        foreach ($options as $option) {
            if (! is_string($option) || $option === '') {
                throw new InvalidArgumentException("The field '{$key}' has an option that is not a non-empty string.");
            }
        }

        if ($options === [] || $options !== array_unique($options)) {
            throw new InvalidArgumentException("The field '{$key}' needs a non-empty list of distinct options.");
        }

        $field = new self(self::CHOICE, self::assertKey($key), self::assertLabel($key, $label), $default, null, $options);

        return $field->assertDefault();
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function default(): string|bool
    {
        return $this->default;
    }

    public function maxLength(): ?int
    {
        return $this->maxLength;
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Whether a submitted value is one this field holds. Nothing is coerced: a
     * value that is not accepted is a 422 naming the key, never a silent cast.
     *
     * Text is counted with `mb_strlen` and refuses control characters, which is
     * the guard `Nickname` already carries: the text is painted at 96px on a
     * television, and a line break inside it breaks the layout.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this->kind) {
            self::TEXT => is_string($value)
                && preg_match('/[\p{C}]/u', $value) !== 1
                && mb_strlen($value) <= (int) $this->maxLength,
            self::TOGGLE => is_bool($value),
            default => is_string($value) && in_array($value, $this->options, true),
        };
    }

    /**
     * The field as the lobby form reads it. Every key is always present, with an
     * explicit null where it does not apply: a key that is sometimes there is a
     * contract nobody can type.
     *
     * @return array{key: string, kind: string, label: string, default: string|bool, max_length: int|null, options: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'label' => $this->label,
            'default' => $this->default,
            'max_length' => $this->maxLength,
            'options' => $this->options,
        ];
    }

    /**
     * @return array{key: string, kind: string, label: string, default: string|bool, max_length: int|null, options: list<string>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function assertDefault(): self
    {
        // A declared default is never empty (TR-51): emptying a box is a decision
        // the table makes, never one that ships.
        if ($this->kind === self::TEXT && $this->default === '') {
            throw new InvalidArgumentException("The field '{$this->key}' declares an empty default.");
        }

        if (! $this->accepts($this->default)) {
            throw new InvalidArgumentException("The field '{$this->key}' declares a default it does not accept.");
        }

        return $this;
    }

    private static function assertKey(string $key): string
    {
        if (! RoomConfig::isValidKey($key)) {
            throw new InvalidArgumentException("'{$key}' is not a room configuration key.");
        }

        return $key;
    }

    private static function assertLabel(string $key, string $label): string
    {
        if (trim($label) === '' || preg_match('/[\p{C}]/u', $label) === 1) {
            throw new InvalidArgumentException("The field '{$key}' needs a label with no control characters.");
        }

        return $label;
    }
}
