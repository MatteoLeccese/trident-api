<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Shared\Domain\ValueObjects\Version;

/**
 * **The one and only state shape of the system.**
 *
 * It is what `GET /api/v1/games/{id}` returns and what `broadcastWith()` sends.
 * A test makes sure the two paths produce identical bytes: the old controller
 * silently dropped fields that its own service returned, and that is exactly the
 * failure this prevents.
 *
 * A full payload and not an incremental one, on purpose: this way a lost event
 * heals itself, and late joining, reconnection and the sleeping television are
 * all the same trivial case.
 *
 * **It never contains a credential**: not the token, not its hash, not the
 * handover code. A test enforces it.
 */
final class GameSnapshot
{
    private function __construct(
        private readonly GameId $id,
        private readonly JoinCode $joinCode,
        private readonly string $status,
        private readonly SeatRoster $seats,
        private readonly Version $version,
        private readonly DateTimeImmutable $lastActivityAt,
    ) {}

    public static function of(
        GameId $id,
        JoinCode $joinCode,
        string $status,
        SeatRoster $seats,
        Version $version,
        DateTimeImmutable $lastActivityAt,
    ): self {
        return new self($id, $joinCode, $status, $seats, $version, $lastActivityAt);
    }

    /**
     * @return array{
     *     game_id: string,
     *     version: int,
     *     status: string,
     *     join_code: string|null,
     *     seats: list<array{seat: int, nickname: string, roles: list<string>}>,
     *     last_activity_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'game_id' => $this->id->value(),
            'version' => $this->version->value(),
            'status' => $this->status,
            // A terminal game has released its code, so both delivery paths carry
            // null for it: the row no longer holds the code, and a live aggregate
            // that still remembers one must not project a code nobody can use.
            'join_code' => GameStatus::isTerminal($this->status)
                ? null
                : $this->joinCode->value(),
            // Array of objects with an explicit `seat`: never a positional map,
            // which was another of the ways the old system diverged.
            'seats' => $this->seats->toArray(),
            'last_activity_at' => $this->lastActivityAt->format(DateTimeInterface::ATOM),
        ];
    }

    public function gameId(): GameId
    {
        return $this->id;
    }

    public function version(): Version
    {
        return $this->version;
    }
}
