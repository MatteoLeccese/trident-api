<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

/**
 * The client's identifier for **one write intention**, carried in `X-Request-Id`.
 *
 * It is a UUID because `game_moves.request_id` is a `uuid` column with a unique
 * index, and that index **is** the idempotency ledger: the same intention sent
 * twice appends one row, so the second attempt answers with the state the first
 * one produced instead of advancing the turn again.
 *
 * A type of its own and not a `Uuid`, although it holds one: a game's identity
 * and a write's identity are both UUIDs and are never interchangeable.
 */
final class RequestId
{
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value)->value());
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
