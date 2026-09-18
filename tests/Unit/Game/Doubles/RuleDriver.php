<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Doubles;

use LogicException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\Move;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\PendingChoice;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\TilePool;
use Src\Shared\Infrastructure\Service\FrozenClock;

/**
 * **A test convenience around `Game`, and nothing else.**
 *
 * It used to be the framework loop itself, written here because the aggregate
 * had no `start()` and no `drawTile()`. It has them now, so every decision this
 * class once took — the status, the cursor, the stage, the pool, the reshuffle
 * of a stage entered twice, the version — belongs to `Game` and is read back
 * from it here. Two implementations of the loop is the failure this project was
 * rebuilt to undo, so what remains may not decide anything.
 *
 * What it adds is what only a test wants: a literal seed, a frozen clock, a
 * chainable call, positions as plain integers, "draw the lowest position until
 * the game stops asking", and the move log accumulated the way the repository
 * accumulates it — `Game::pullMoves()` empties the pending log, so a caller that
 * wants the whole history has to keep it.
 */
final class RuleDriver
{
    /** A literal seed: a run that depends on chance proves nothing. */
    public const SEED = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

    /** @var list<Move> every move the aggregate has appended, oldest first */
    private array $moves = [];

    /** @var list<Effect> every effect the rules have emitted, oldest first */
    private array $effects = [];

    private function __construct(private readonly Game $game, private RuleSet $ruleSet) {}

    /**
     * `$stored` is the raw `games.room_config` blob as the table wrote it, which
     * the aggregate resolves through the ruleset's own spec when play begins.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function of(RuleSet $ruleSet, SeatRoster $seats, array $stored = []): self
    {
        $driver = new self(
            Game::open(
                GameId::random(),
                JoinCode::generate(),
                ControllerToken::generate(),
                $seats,
                self::clock(),
                Seed::fromString(self::SEED),
            ),
            $ruleSet,
        );

        if ($stored !== []) {
            $driver->game->configureRoom(RoomConfig::fromArray($stored), self::clock());
        }

        return $driver->collect();
    }

    /**
     * The deployment moved on: the same game, pinned to the same id, handed the
     * next version of the ruleset it started under.
     */
    public function upgradedTo(RuleSet $ruleSet): self
    {
        $this->ruleSet = $ruleSet;

        return $this;
    }

    public function start(): self
    {
        $this->game->start($this->ruleSet, self::clock());

        return $this->collect();
    }

    public function draw(int $position): self
    {
        $this->game->drawTile(PoolPosition::fromInt($position), $this->ruleSet, self::clock());

        return $this->collect();
    }

    public function choose(string $option): self
    {
        $this->game->answerChoice($option, $this->ruleSet, self::clock());

        return $this->collect();
    }

    /** Draws the lowest untaken position until the game stops asking for one. */
    public function playOut(int $limit = 200): self
    {
        for ($turn = 0; $turn < $limit && $this->status() === GameStatus::RUNNING; $turn++) {
            $this->draw($this->firstUntakenPosition());
        }

        return $this;
    }

    public function firstUntakenPosition(): int
    {
        $pool = $this->game->pool();

        for ($position = 1; $position <= $pool->count(); $position++) {
            if (! $pool->isTaken(PoolPosition::fromInt($position))) {
                return $position;
            }
        }

        throw new LogicException("The pool of '{$this->stage()}' is exhausted.");
    }

    /**
     * @return list<array{position: int, tile: string|null, taken: bool}>
     */
    public function projectPool(): array
    {
        return $this->game->projectPool($this->ruleSet);
    }

    public function game(): Game
    {
        return $this->game;
    }

    public function status(): string
    {
        return $this->game->status();
    }

    public function stage(): string
    {
        return $this->game->stage()?->value() ?? throw new LogicException('This game has not started.');
    }

    public function pool(): TilePool
    {
        return $this->game->pool();
    }

    public function cursor(): ?SeatNumber
    {
        return $this->game->currentSeat();
    }

    public function seats(): SeatRoster
    {
        return $this->game->seats();
    }

    public function pendingChoice(): ?PendingChoice
    {
        return $this->game->pendingChoice();
    }

    public function finishReason(): ?string
    {
        return $this->game->finishReason();
    }

    public function state(): RuleState
    {
        return $this->game->ruleState() ?? throw new LogicException('This game has not started.');
    }

    public function turnNumber(): int
    {
        return $this->game->turnNumber();
    }

    public function visitsOf(string $stage): int
    {
        return $this->game->stageVisits($stage);
    }

    /** @return list<Effect> */
    public function effects(): array
    {
        return $this->effects;
    }

    /**
     * The draws as the move log records them, which is what the draw history is
     * reconstituted from.
     *
     * @return list<array{stage: string, seat: int, position: int, tile: string}>
     */
    public function history(): array
    {
        $history = [];

        foreach ($this->moves as $move) {
            if ($move->kind() !== MoveKind::TILE_DRAWN) {
                continue;
            }

            /** @var array{stage: string, position: int, tile: string} $payload */
            $payload = $move->payload();

            $history[] = [
                'stage' => $payload['stage'],
                'seat' => $move->actorSeat()?->value() ?? 0,
                'position' => $payload['position'],
                'tile' => $payload['tile'],
            ];
        }

        return $history;
    }

    /** @return list<string> the stage of each draw, in order */
    public function stagesPlayed(): array
    {
        return array_map(static fn (array $draw): string => $draw['stage'], $this->history());
    }

    /** @return list<Move> */
    public function moves(): array
    {
        return $this->moves;
    }

    /**
     * Takes what the aggregate has produced, exactly as the repository does: the
     * pending moves, which pulling empties, and the effects of the write that has
     * just happened.
     */
    private function collect(): self
    {
        $this->moves = [...$this->moves, ...$this->game->pullMoves()];
        $this->effects = [...$this->effects, ...$this->game->lastEffects()];

        return $this;
    }

    private static function clock(): FrozenClock
    {
        return FrozenClock::at('2026-09-17 21:00:00');
    }
}
