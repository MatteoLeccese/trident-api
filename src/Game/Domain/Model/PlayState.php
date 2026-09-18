<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\PendingChoice;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\TilePool;

/**
 * Everything a game in play holds beyond its identity, its roster and its
 * version: the port between `Game` and storage, in one object.
 *
 * It exists so that `Game::reconstitute()` keeps one trailing parameter instead
 * of eleven, and so that the aggregate publishes the shuffle seed through
 * exactly one reader, whose only caller is `EloquentGameRepository`. The seed is
 * the single secret of the system (TR-09), and a bare `Game::seed()` getter
 * beside the other readers would be an invitation to use it somewhere else.
 *
 * `turnNumber` is **not** a member. It is the number of draws the game has taken,
 * so it is `count($drawLog)` by construction, and the log is itself rebuilt from
 * the `tile_drawn` rows of `game_moves`. A column and a derivation that must
 * agree are two sources of truth for one number.
 *
 * `none()` is the state of a lobby: no ruleset, no seed, no stage, no cursor, no
 * pool and no settings.
 */
final class PlayState
{
    /**
     * @param  array<string, int>  $stageVisits
     * @param  list<Effect>  $lastEffects
     */
    private function __construct(
        private readonly ?string $ruleSetId,
        private readonly ?Seed $seed,
        private readonly ?StageId $stage,
        private readonly ?SeatNumber $currentSeat,
        private readonly TilePool $pool,
        private readonly DrawLog $drawLog,
        private readonly ?RuleState $ruleState,
        private readonly RoomConfig $roomConfig,
        private readonly ?PendingChoice $pendingChoice,
        private readonly ?string $finishReason,
        private readonly array $stageVisits,
        private readonly array $lastEffects,
    ) {}

    /**
     * @param  array<string, int>  $stageVisits  how many times each stage has been entered
     * @param  list<Effect>  $lastEffects  the effects of the write this version came from
     */
    public static function of(
        ?string $ruleSetId,
        ?Seed $seed,
        ?StageId $stage,
        ?SeatNumber $currentSeat,
        TilePool $pool,
        DrawLog $drawLog,
        ?RuleState $ruleState,
        RoomConfig $roomConfig,
        ?PendingChoice $pendingChoice,
        ?string $finishReason,
        array $stageVisits,
        array $lastEffects = [],
    ): self {
        return new self(
            $ruleSetId,
            $seed,
            $stage,
            $currentSeat,
            $pool,
            $drawLog,
            $ruleState,
            $roomConfig,
            $pendingChoice,
            $finishReason,
            $stageVisits,
            $lastEffects,
        );
    }

    /** The state of a game that has not started: everything empty or absent. */
    public static function none(): self
    {
        return new self(
            null,
            null,
            null,
            null,
            TilePool::reconstitute([], []),
            DrawLog::empty(),
            null,
            RoomConfig::empty(),
            null,
            null,
            [],
            [],
        );
    }

    public function ruleSetId(): ?string
    {
        return $this->ruleSetId;
    }

    /** The shuffle seed, which leaves the server in no body of any kind (TR-09). */
    public function seed(): ?Seed
    {
        return $this->seed;
    }

    public function stage(): ?StageId
    {
        return $this->stage;
    }

    public function currentSeat(): ?SeatNumber
    {
        return $this->currentSeat;
    }

    public function pool(): TilePool
    {
        return $this->pool;
    }

    public function drawLog(): DrawLog
    {
        return $this->drawLog;
    }

    public function ruleState(): ?RuleState
    {
        return $this->ruleState;
    }

    public function roomConfig(): RoomConfig
    {
        return $this->roomConfig;
    }

    public function pendingChoice(): ?PendingChoice
    {
        return $this->pendingChoice;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * @return array<string, int>
     */
    public function stageVisits(): array
    {
        return $this->stageVisits;
    }

    /**
     * The effects of the write that produced this version, which have no column:
     * they are the `effects` of the last entry of `game_moves`.
     *
     * @return list<Effect>
     */
    public function lastEffects(): array
    {
        return $this->lastEffects;
    }

    /** The turn cursor, which is the length of the log and never a stored number. */
    public function turnNumber(): int
    {
        return count($this->drawLog->all());
    }
}
