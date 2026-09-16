<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use DateTimeImmutable;
use Src\Game\Domain\Exceptions\GameAlreadyFinishedException;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Domain\Service\Clock;
use Src\Shared\Domain\ValueObjects\Version;

/**
 * The aggregate root. Plain PHP: no Laravel, no Eloquent, no cache.
 *
 * The headline failure of the audit of the old system was that **nobody owned**
 * the phase, the turn cursor or the trident flag, so each of them acquired
 * contradictory definitions on its own. The fix is not a better DTO: it is a
 * model with write methods that own the invariants.
 *
 * Rules this class guarantees:
 *  - the version goes up one at a time, and only when something really changes;
 *  - a rejected write leaves no half-finished effect behind;
 *  - every change leaves exactly one entry in the log, with a unique sequence;
 *  - the activity window slides on every write (never an absolute clock);
 *  - a finished game is not touched.
 */
final class Game
{
    /** @var list<Move> */
    private array $pendingMoves = [];

    private function __construct(
        private readonly GameId $id,
        private readonly JoinCode $joinCode,
        private readonly string $controllerTokenHash,
        private string $status,
        private SeatRoster $seats,
        private Version $version,
        private DateTimeImmutable $lastActivityAt,
        private int $lastSequence,
    ) {}

    public static function open(
        GameId $id,
        JoinCode $joinCode,
        ControllerToken $controllerToken,
        SeatRoster $seats,
        Clock $clock,
    ): self {
        $game = new self(
            $id,
            $joinCode,
            $controllerToken->hash(),
            GameStatus::LOBBY,
            $seats,
            Version::initial(),
            $clock->now(),
            0,
        );

        $game->record(MoveKind::GAME_OPENED, null, ['seats' => $seats->count()]);

        return $game;
    }

    /**
     * Reconstruction from persistence. The repository uses it; nothing else.
     *
     * @param  list<Move>  $pendingMoves
     */
    public static function reconstitute(
        GameId $id,
        JoinCode $joinCode,
        string $controllerTokenHash,
        string $status,
        SeatRoster $seats,
        Version $version,
        DateTimeImmutable $lastActivityAt,
        int $lastSequence,
    ): self {
        return new self(
            $id,
            $joinCode,
            $controllerTokenHash,
            $status,
            $seats,
            $version,
            $lastActivityAt,
            $lastSequence,
        );
    }

    public function renameSeat(SeatNumber $seat, Nickname $nickname, Clock $clock): void
    {
        $this->assertNotOver();

        // The roster validates before anything here is touched: if it throws, the
        // game is left exactly as it was.
        $renamed = $this->seats->rename($seat, $nickname);

        $this->seats = $renamed;
        $this->touch($clock);
        $this->record(MoveKind::SEAT_RENAMED, $seat, ['nickname' => $nickname->value()]);
    }

    public function abandon(Clock $clock): void
    {
        if (GameStatus::isTerminal($this->status)) {
            return;
        }

        $this->status = GameStatus::ABANDONED;
        $this->touch($clock);
        $this->record(MoveKind::GAME_ABANDONED);
    }

    public function isControlledBy(ControllerToken $token): bool
    {
        return $token->matchesHash($this->controllerTokenHash);
    }

    public function snapshot(): GameSnapshot
    {
        return GameSnapshot::of(
            $this->id,
            $this->joinCode,
            $this->status,
            $this->seats,
            $this->version,
            $this->lastActivityAt,
        );
    }

    /**
     * Returns the pending moves and empties them. The repository persists them;
     * if they were not emptied, they would be written twice.
     *
     * @return list<Move>
     */
    public function pullMoves(): array
    {
        $moves = $this->pendingMoves;
        $this->pendingMoves = [];

        return $moves;
    }

    public function id(): GameId
    {
        return $this->id;
    }

    public function joinCode(): JoinCode
    {
        return $this->joinCode;
    }

    public function controllerTokenHash(): string
    {
        return $this->controllerTokenHash;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function seats(): SeatRoster
    {
        return $this->seats;
    }

    public function version(): Version
    {
        return $this->version;
    }

    public function lastActivityAt(): DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function lastSequence(): int
    {
        return $this->lastSequence;
    }

    private function assertNotOver(): void
    {
        if (GameStatus::isTerminal($this->status)) {
            throw new GameAlreadyFinishedException;
        }
    }

    private function touch(Clock $clock): void
    {
        $this->version = $this->version->next();
        $this->lastActivityAt = $clock->now();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $kind, ?SeatNumber $actorSeat = null, array $payload = []): void
    {
        $this->lastSequence++;
        $this->pendingMoves[] = Move::of($this->lastSequence, $kind, $actorSeat, $payload);
    }
}
