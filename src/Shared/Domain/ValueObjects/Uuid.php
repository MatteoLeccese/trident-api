<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final class Uuid implements JsonSerializable, Stringable
{
    // \z rather than $: in PCRE, $ also matches just before a trailing newline.
    private const PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private function __construct(private readonly string $value) {}

    public static function random(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        /** @var array<int, string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks));
    }

    public static function fromString(string $value): self
    {
        $normalised = strtolower($value);

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw new InvalidArgumentException("'{$value}' no es un UUID válido.");
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

    /**
     * Without this, a Uuid inside the envelope serialises as `{}`: json_encode only
     * emits public properties, and the value object's ones are private.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
