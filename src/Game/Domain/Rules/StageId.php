<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;

/**
 * The identifier of a stage, owned by the RuleSet and opaque to everything else.
 *
 * `trident.v1` names two (TR-16), and no file of the framework or of the client
 * branches on either: a stage is stored as VARCHAR, projected as a string and
 * painted with the label its `StageSequence` carries.
 *
 * It is also the shuffle's stream, so each stage draws an independently shuffled
 * pool from the single `games.shuffle_seed` (TR-31).
 *
 * The format is the framework's only claim on it — an identifier it can store,
 * hash and use as the tail of a room configuration key — never its meaning.
 */
final class StageId implements JsonSerializable
{
    public const MAX_LENGTH = 32;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        // \z rather than $: in PCRE, $ also matches just before a trailing newline.
        if (preg_match('/\A[a-z][a-z0-9_]{0,'.(self::MAX_LENGTH - 1).'}\z/', $value) !== 1) {
            throw new InvalidArgumentException("'{$value}' is not a stage id.");
        }

        return new self($value);
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
}
