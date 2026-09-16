<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * An entry in the append-only `game_moves` log.
 *
 * The log is **not authoritative** — the `games` row is — but it serves the version
 * counter, the television's history, the idempotency book and the audit trail.
 * See documentation/conventions/no-cache-as-truth.md.
 */
final class Move
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private readonly int $sequence,
        private readonly string $kind,
        private readonly ?SeatNumber $actorSeat,
        private readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(int $sequence, string $kind, ?SeatNumber $actorSeat = null, array $payload = []): self
    {
        return new self($sequence, $kind, $actorSeat, $payload);
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function actorSeat(): ?SeatNumber
    {
        return $this->actorSeat;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
