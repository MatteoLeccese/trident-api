<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Src\Game\Domain\Exceptions\GameAlreadyFinishedException;
use Src\Game\Domain\Exceptions\GameNotInLobbyException;
use Src\Game\Domain\Exceptions\GameNotRunningException;
use Src\Game\Domain\Exceptions\NoPendingChoiceException;
use Src\Game\Domain\Exceptions\PoolPositionAlreadyTakenException;
use Src\Game\Domain\Exceptions\RuleSetMismatchException;
use Src\Game\Domain\Rules\ChoiceContext;
use Src\Game\Domain\Rules\DrawContext;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\EffectKind;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\GameContext;
use Src\Game\Domain\Rules\Outcome;
use Src\Game\Domain\Rules\PendingChoice;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\Draw;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\TilePool;
use Src\Shared\Domain\Service\Clock;
use Src\Shared\Domain\ValueObjects\Version;

/**
 * The aggregate root. Plain PHP: no Laravel, no Eloquent, no cache.
 *
 * The headline failure of the audit of the old system was that **nobody owned**
 * the phase, the turn cursor or the trident flag, so each of them acquired
 * contradictory definitions on its own. The fix is not a better DTO: it is a
 * model with write methods that own the invariants. There is therefore **one**
 * framework loop, and it is this class: a second caller that also decided a
 * status, a cursor or a stage would be that failure returning with a new name.
 *
 * Rules this class guarantees:
 *  - the version goes up one at a time, and only when something really changes;
 *  - a rejected write leaves no half-finished effect behind;
 *  - every change leaves exactly one entry in the log, with a unique sequence;
 *  - the activity window slides on every write (never an absolute clock);
 *  - a finished game is not touched.
 *
 * **What it decides, and all it decides**, as
 * documentation/conventions/rule-set-seam.md fixes it: the status allows the
 * write, there is a current seat, the position exists and is not taken, the game
 * has not finished. Then it calls the ruleset and applies what comes back. Who
 * is elected, when a stage ends and who answers a challenge are decisions of a
 * `RuleSet` and are never read, branched on or second-guessed here.
 *
 * *"Is it your turn?"* is not on that list and cannot be: there is one phone and
 * one `ControllerToken`, so the server cannot know which human is holding it.
 * `drawTile()` takes no seat and attributes the draw to the current seat it
 * already holds (TR-11).
 */
final class Game
{
    /** @var list<Move> */
    private array $pendingMoves = [];

    /** The ruleset id this game was pinned to when play began (`games.rule_set_id`). */
    private ?string $ruleSetId = null;

    /** The stage in play. Opaque: only the ruleset knows what it means. */
    private ?StageId $stage = null;

    /** The one seat that acts (TR-10). Null until play begins. */
    private ?SeatNumber $currentSeat = null;

    private TilePool $pool;

    private DrawLog $drawLog;

    private RoomConfig $roomConfig;

    private ?RuleState $ruleState = null;

    private int $turnNumber = 0;

    private ?PendingChoice $pendingChoice = null;

    private ?string $finishReason = null;

    /** @var array<string, int> how many times each stage has been entered */
    private array $stageVisits = [];

    /** @var list<Effect> the effects of the most recent write */
    private array $lastEffects = [];

    private function __construct(
        private readonly GameId $id,
        private readonly JoinCode $joinCode,
        private readonly string $controllerTokenHash,
        private string $status,
        private SeatRoster $seats,
        private Version $version,
        private DateTimeImmutable $lastActivityAt,
        private int $lastSequence,
        private ?Seed $seed,
    ) {
        $this->pool = TilePool::reconstitute([], []);
        $this->drawLog = DrawLog::empty();
        $this->roomConfig = RoomConfig::empty();
    }

    /**
     * A game is born with its shuffle seed, which is a server secret and leaves
     * the server in no body of any kind (TR-09). It is a parameter so that a test
     * can run on a literal one: a run that depends on chance proves nothing.
     */
    public static function open(
        GameId $id,
        JoinCode $joinCode,
        ControllerToken $controllerToken,
        SeatRoster $seats,
        Clock $clock,
        ?Seed $seed = null,
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
            $seed ?? Seed::generate(),
        );

        $game->record(MoveKind::GAME_OPENED, null, ['seats' => $seats->count()]);

        return $game;
    }

    /**
     * Reconstruction from persistence. The repository uses it; nothing else.
     *
     * The play state arrives as one object rather than as eleven trailing
     * parameters, and it defaults to `PlayState::none()` so that a caller with
     * nothing to restore — a lobby — says so by omission.
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
        ?PlayState $play = null,
    ): self {
        $play ??= PlayState::none();

        $game = new self(
            $id,
            $joinCode,
            $controllerTokenHash,
            $status,
            $seats,
            $version,
            $lastActivityAt,
            $lastSequence,
            $play->seed(),
        );

        $game->ruleSetId = $play->ruleSetId();
        $game->stage = $play->stage();
        $game->currentSeat = $play->currentSeat();
        $game->pool = $play->pool();
        $game->drawLog = $play->drawLog();
        $game->ruleState = $play->ruleState();
        $game->roomConfig = $play->roomConfig();
        $game->pendingChoice = $play->pendingChoice();
        $game->finishReason = $play->finishReason();
        $game->stageVisits = $play->stageVisits();
        // The effects of the write this version came from, which the log stores
        // beside it: a game read back out of a row projects the same bytes as the
        // aggregate that was broadcast when it was written.
        $game->lastEffects = $play->lastEffects();
        // The cursor is the length of the log and is never stored beside it.
        $game->turnNumber = $play->turnNumber();

        return $game;
    }

    /**
     * Everything this game holds beyond its identity, its roster and its version,
     * for the one caller that has to write it down.
     *
     * It is the only reader of the shuffle seed, which no response body of any
     * kind may carry (TR-09).
     */
    public function playState(): PlayState
    {
        return PlayState::of(
            $this->ruleSetId,
            $this->seed,
            $this->stage,
            $this->currentSeat,
            $this->pool,
            $this->drawLog,
            $this->ruleState,
            $this->roomConfig,
            $this->pendingChoice,
            $this->finishReason,
            $this->stageVisits,
            $this->lastEffects,
        );
    }

    /**
     * A seat's name, which every status but a terminal one accepts.
     *
     * A rename that changes nothing writes nothing: no version, no move and
     * therefore no broadcast, the same guarantee `configureRoom()` and
     * `reorderSeats()` give. The phone re-submitting the name it is already
     * showing — a double tap with no `X-Request-Id`, a keyboard that dismisses
     * and re-fires the save — is a common gesture, and the version rises only
     * when something really changed.
     */
    public function renameSeat(SeatNumber $seat, Nickname $nickname, Clock $clock): void
    {
        $this->assertNotOver();

        // The roster validates before anything here is touched: if it throws, the
        // game is left exactly as it was.
        $renamed = $this->seats->rename($seat, $nickname);

        if ($renamed->toArray() === $this->seats->toArray()) {
            return;
        }

        $this->seats = $renamed;
        $this->touch($clock);
        $this->record(MoveKind::SEAT_RENAMED, $seat, ['nickname' => $nickname->value()]);
    }

    /**
     * The table's settings, which only the lobby accepts: they are frozen when
     * play begins, and they are read through the ruleset's own spec exactly once,
     * by `start()`.
     *
     * An edit that changes nothing writes nothing: no version, no move and
     * therefore no broadcast. Picking a box up, thinking, and putting it back is
     * a common gesture, and the version rises only when something really changed.
     */
    public function configureRoom(RoomConfig $config, Clock $clock): void
    {
        $this->assertInLobby();

        if ($config->toArray() === $this->roomConfig->toArray()) {
            return;
        }

        $this->roomConfig = $config;
        $this->touch($clock);
        $this->record(MoveKind::ROOM_CONFIGURED, null, ['room_config' => $config->toArray()]);
    }

    /**
     * Renumbers the table, which only the lobby accepts: `game_moves.actor_seat`
     * is a bare number and not a reference to a person, so a seat renumbered once
     * there is history would make that history name somebody else.
     *
     * `$order` is the **absolute permutation** — the seat numbers as they stand
     * now, in the order they will stand — and not a "move 3 to 1". A drag emits
     * one intention and many frames, and only an intention that names the
     * arrangement it wants stays true when a frame is repeated.
     *
     * That payload is not idempotent by itself, because it renumbers the very
     * domain it addresses: applying `[3, 1, 2]` twice is not applying it once.
     * What makes it safe is not this method but the write protocol around it —
     * `expected_version`, which a repeat no longer matches, and `X-Request-Id`
     * with its unique index, which returns the snapshot of the first attempt
     * instead of applying a second. Here the guarantee is narrower and exact:
     * the identity permutation changes nothing, so it bumps no version, appends
     * no move and broadcasts nothing.
     *
     * @param  list<SeatNumber>  $order
     */
    public function reorderSeats(array $order, Clock $clock): void
    {
        $this->assertInLobby();

        // The roster validates the permutation before anything here is touched.
        $reordered = $this->seats->reorder($order);

        if ($reordered->toArray() === $this->seats->toArray()) {
            return;
        }

        $this->seats = $reordered;
        $this->touch($clock);
        $this->record(MoveKind::SEATS_REORDERED, null, [
            'order' => array_map(static fn (SeatNumber $seat): int => $seat->value(), array_values($order)),
        ]);
    }

    /**
     * Play begins: the game is pinned to its ruleset, the table's settings are
     * resolved against that ruleset's spec and frozen, the first stage's pool is
     * materialised, and the ruleset takes the first decision of the game — which
     * runs before a cursor exists (TR-17).
     *
     * Nothing is written until both ruleset calls have returned, so a ruleset
     * that refuses a table leaves a lobby and not half a game.
     */
    public function start(RuleSet $rules, Clock $clock): void
    {
        $this->assertInLobby();

        $seed = $this->seed ?? Seed::generate();
        $ruleState = $this->ruleState ?? RuleState::initial($rules->stateVersion());
        // The tolerant reader of documentation/conventions/room-config.md, run
        // exactly once: every key the spec declares, stored value or default.
        $roomConfig = RoomConfig::resolve($this->roomConfig->toArray(), $rules->roomConfigSpec());
        $stage = $rules->stages()->first();

        $context = GameContext::of(
            $stage,
            $this->seats,
            null,
            $this->drawLog,
            $this->turnNumber,
            $ruleState,
            $roomConfig,
        );

        $pool = TilePool::fromDeck($rules->deck($context), $seed, $this->streamOf($stage, 1));
        $outcome = $rules->onGameStarted($context);

        $this->seed = $seed;
        $this->ruleState = $ruleState;
        $this->roomConfig = $roomConfig;
        $this->ruleSetId = $rules->id();
        $this->stage = $stage;
        $this->stageVisits = [$stage->value() => 1];
        $this->pool = $pool;
        $this->status = GameStatus::RUNNING;

        $effects = $this->apply($outcome, $rules);

        $this->touch($clock);
        $this->record(MoveKind::GAME_STARTED, null, [
            'rule_set_id' => $rules->id(),
            'stage' => $this->stage->value(),
            'effects' => $effects,
        ]);
    }

    /**
     * One position turned over.
     *
     * **It takes no seat.** There is one phone and one `ControllerToken`, so the
     * server cannot know which human is holding it: the draw is attributed to the
     * current seat the aggregate itself holds (TR-11). A seat sent by a client
     * would record a neighbour's tap against the wrong name for ever, and
     * `not_your_turn` does not exist in this system.
     *
     * The four checks are the aggregate's own: the game is in play, there is a
     * current seat, the position is in this pool and it has not been taken. Every
     * other question is the ruleset's.
     */
    public function drawTile(PoolPosition $position, RuleSet $rules, Clock $clock): void
    {
        $this->assertRunning();
        $this->assertPinnedTo($rules);

        $seat = $this->currentSeat ?? throw new LogicException('A game in play always has a current seat.');
        $stage = $this->stage ?? throw new LogicException('A game in play always has a stage.');
        $pool = $this->pool;

        // The pool owns both position checks and throws its own domain errors, so
        // they are asked here and not re-implemented: a position outside the pool
        // and a position already taken are two different refusals, and both land
        // before the ruleset is consulted.
        $tile = $pool->at($position);

        if ($pool->isTaken($position)) {
            throw new PoolPositionAlreadyTakenException("Position {$position->value()} has already been taken.");
        }

        $turnNumber = $this->turnNumber + 1;

        $outcome = $rules->onTileDrawn(DrawContext::of(
            $seat,
            $position,
            $tile,
            $this->seats,
            $this->drawLog,
            $pool,
            $turnNumber,
            $stage,
            $this->ruleStateInPlay(),
            $this->roomConfig,
        ));

        // The draw lands before the outcome is applied: applying one may replace
        // this pool with the next stage's, and the position was taken from this
        // one.
        $this->turnNumber = $turnNumber;
        $this->pool = $pool->take($position, $seat);
        $this->drawLog = $this->drawLog->append(Draw::of($stage->value(), $seat, $position, $tile));

        $effects = $this->apply($outcome, $rules);

        $this->touch($clock);
        $this->record(MoveKind::TILE_DRAWN, $seat, [
            'stage' => $stage->value(),
            'position' => $position->value(),
            'tile' => $tile->value(),
            'effects' => $effects,
        ]);
    }

    /**
     * The answer to the question a rule parked the game on, from the seat that
     * question named.
     *
     * The seat is not a parameter for the same reason a draw carries none: the
     * pending choice already names it. `ChoiceContext` refuses an answer that is
     * not one of the options offered, and it does so before anything here is
     * touched.
     */
    public function answerChoice(string $option, RuleSet $rules, Clock $clock): void
    {
        $this->assertNotOver();
        $this->assertPinnedTo($rules);

        $choice = $this->pendingChoice;

        if ($this->status !== GameStatus::AWAITING_CHOICE || $choice === null) {
            throw new NoPendingChoiceException;
        }

        $stage = $this->stage ?? throw new LogicException('A game in play always has a stage.');

        $context = ChoiceContext::of(
            $choice->seat(),
            $option,
            $choice,
            $this->seats,
            $this->drawLog,
            $this->pool,
            $this->turnNumber,
            $stage,
            $this->ruleStateInPlay(),
            $this->roomConfig,
        );

        $outcome = $rules->onChoiceMade($context);

        $this->pendingChoice = null;
        $this->status = GameStatus::RUNNING;

        $effects = $this->apply($outcome, $rules);

        $this->touch($clock);
        $this->record(MoveKind::CHOICE_ANSWERED, $choice->seat(), [
            'option' => $option,
            'effects' => $effects,
        ]);
    }

    /**
     * Ends a game without consulting any rule (TR-35), stating why.
     *
     * The reason is required and not defaulted: a rematch and an expiry both
     * land on `abandoned`, and a caller that did not have to say which one it
     * was would leave the two indistinguishable in the only row anybody will
     * ever count.
     *
     * Abandoning a game that has already ended is a no-op and keeps the reason
     * it ended with: the first terminal answer is the true one.
     */
    public function abandon(Clock $clock, string $reason): void
    {
        if (! FinishReason::isValid($reason)) {
            throw new InvalidArgumentException("'{$reason}' is not a finish reason.");
        }

        if (GameStatus::isTerminal($this->status)) {
            return;
        }

        $this->status = GameStatus::ABANDONED;
        $this->finishReason = $reason;
        $this->pendingChoice = null;
        $this->touch($clock);
        $this->record(MoveKind::GAME_ABANDONED);
    }

    public function isControlledBy(ControllerToken $token): bool
    {
        return $token->matchesHash($this->controllerTokenHash);
    }

    /**
     * The one state shape of the system, built here and nowhere else.
     *
     * Two of its fields come from outside the aggregate, and neither can be
     * reached from inside it. The pool projects through `RuleSet::visibility()`,
     * which is a rule (TR-07), and a game holds a ruleset **id** rather than the
     * object — whoever resolves that id hands it back in. `tv_idle_notice_minutes`
     * is a deployment value, the same for every game, and the domain may not read
     * a configuration file: it is injected at the composition root and carried
     * here by `Src\Game\Application\Service\GameProjector`.
     *
     * A game that is pinned to a ruleset refuses to project without it, rather
     * than emitting an empty board for a table that is mid-play.
     */
    public function snapshot(?RuleSet $rules, int $tvIdleNoticeMinutes): GameSnapshot
    {
        if ($this->ruleSetId !== null && $rules === null) {
            throw new LogicException('A game in play projects through the ruleset it is pinned to.');
        }

        return GameSnapshot::of(
            $this->id,
            $this->joinCode,
            $this->status,
            $this->seats,
            $this->version,
            $this->lastActivityAt,
            $this->stage,
            $this->currentSeat,
            $rules === null ? [] : $this->projectPool($rules),
            $this->roomConfig,
            $tvIdleNoticeMinutes,
            array_map(static fn (Effect $effect): array => $effect->toArray(), $this->lastEffects),
            $this->projectLastDraw(),
        );
    }

    /**
     * The draw that was last made, for a client that cannot read it off the pool.
     *
     * A draw that ends a stage is applied in the same write as the next stage's
     * fresh pool (TR-04, TR-25), so from that version onwards the position that
     * was turned over is untaken and face down in the pool that arrives with it.
     * This reads the log instead, which is the record of what was drawn.
     *
     * It is not filtered through `RuleSet::visibility()` because there is nothing
     * for it to hide: a taken position projects its face under both declared
     * visibilities, and this position is taken by definition.
     *
     * @return array{position: int, tile: string, seat: int}|null
     */
    private function projectLastDraw(): ?array
    {
        $draw = $this->drawLog->last();

        if ($draw === null) {
            return null;
        }

        return [
            'position' => $draw->position()->value(),
            'tile' => $draw->tile()->value(),
            'seat' => $draw->seat()->value(),
        ];
    }

    /**
     * The pool as both clients read it: one projection, asked of the stage and
     * never of the viewer (TR-06). A lobby has no pool and shows none.
     *
     * The ruleset is a parameter because visibility is a rule (TR-07) and this
     * class holds no ruleset: a game is pinned to an id, and whoever resolves
     * that id hands the object back in. It answers the board's presentation too
     * (TR-52), from the same context and in the same call, so that no client has
     * to build the key of the setting behind it.
     *
     * @return list<array{position: int, tile: string|null, taken: bool, seat: int|null, on_board: bool}>
     */
    public function projectPool(RuleSet $rules): array
    {
        $this->assertPinnedTo($rules);

        if ($this->stage === null) {
            return [];
        }

        $context = $this->context($this->stage);

        return $this->pool->project($rules->visibility($context), $rules->boardPresence($context));
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

    public function ruleSetId(): ?string
    {
        return $this->ruleSetId;
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

    public function roomConfig(): RoomConfig
    {
        return $this->roomConfig;
    }

    public function ruleState(): ?RuleState
    {
        return $this->ruleState;
    }

    public function turnNumber(): int
    {
        return $this->turnNumber;
    }

    public function pendingChoice(): ?PendingChoice
    {
        return $this->pendingChoice;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    /** How many times a stage has been entered, which is what gives it a fresh shuffle. */
    public function stageVisits(string $stage): int
    {
        return $this->stageVisits[$stage] ?? 0;
    }

    /**
     * The effects the most recent write produced, in order. The move log carries
     * the same list serialised; this is it as objects, for whoever publishes the
     * write that has just happened.
     *
     * @return list<Effect>
     */
    public function lastEffects(): array
    {
        return $this->lastEffects;
    }

    /**
     * Applies an `Outcome` and nothing else: the framework acts on `assign_role`
     * and carries every other kind to the clients uninterpreted.
     *
     * Everything that can be refused is computed into locals first — a role for a
     * seat that is not at the table, a deck for a stage the ruleset does not
     * know — so a ruleset that throws halfway through leaves the game as it was.
     *
     * @return list<array<string, mixed>> the effects, as the move log stores them
     */
    private function apply(Outcome $outcome, RuleSet $rules): array
    {
        $seats = $this->seats;

        // `assign_role` is the one effect the framework acts on: it writes the
        // role onto the roster (TR-27), which is the only place a role lives
        // (TR-29). Every other kind is carried and interpreted by nobody here.
        foreach ($outcome->effects() as $effect) {
            $seat = $effect->seat();
            $role = $effect->role();

            if ($effect->kind() === EffectKind::ASSIGN_ROLE && $seat !== null && $role !== null) {
                $seats = $seats->assignRole($seat, $role);
            }
        }

        $state = $this->ruleStateInPlay()->with($outcome->ruleStatePatch());

        $this->lastEffects = $outcome->effects();

        if ($outcome->isFinished()) {
            $this->seats = $seats;
            $this->ruleState = $state;
            $this->status = GameStatus::FINISHED;
            $this->finishReason = $outcome->finishReason();
            $this->pendingChoice = null;

            return $this->serialise($outcome);
        }

        $stage = $this->stage;
        $pool = $this->pool;
        $visits = $this->stageVisits;
        $next = $outcome->nextStage();

        if ($next !== null) {
            // A stage change asks the ruleset for its deck again (TR-31). The
            // stream of a stage's first visit is its bare id and a later visit
            // appends its number, so a `nextStage` that points backwards does not
            // deal the identical pool in the identical order from the one seed.
            $stage = $next;
            $visit = ($visits[$next->value()] ?? 0) + 1;
            $visits[$next->value()] = $visit;

            $context = GameContext::of(
                $next,
                $seats,
                $this->currentSeat,
                $this->drawLog,
                $this->turnNumber,
                $state,
                $this->roomConfig,
            );

            $pool = TilePool::fromDeck($rules->deck($context), $this->seedInPlay(), $this->streamOf($next, $visit));
        }

        $this->seats = $seats;
        $this->ruleState = $state;
        $this->stage = $stage;
        $this->stageVisits = $visits;
        $this->pool = $pool;

        if ($outcome->pendingChoice() !== null) {
            // The turn is not over: the cursor stays where it is until the seat
            // the choice names has answered.
            $this->pendingChoice = $outcome->pendingChoice();
            $this->status = GameStatus::AWAITING_CHOICE;

            return $this->serialise($outcome);
        }

        $this->currentSeat = $outcome->overrideNextSeat()
            ?? ($this->currentSeat === null
                ? SeatNumber::first()
                : SeatRing::next($this->currentSeat, $seats->count()));
        $this->status = GameStatus::RUNNING;

        return $this->serialise($outcome);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serialise(Outcome $outcome): array
    {
        return array_map(static fn (Effect $effect): array => $effect->toArray(), $outcome->effects());
    }

    /**
     * One `games.shuffle_seed` gives every stage an independently shuffled pool
     * (TR-31), and a stage entered twice is two pools and not one.
     */
    private function streamOf(StageId $stage, int $visit): string
    {
        return $visit <= 1 ? $stage->value() : $stage->value().'#'.$visit;
    }

    private function seedInPlay(): Seed
    {
        return $this->seed ?? throw new LogicException('A game in play always has a seed.');
    }

    private function ruleStateInPlay(): RuleState
    {
        return $this->ruleState ?? throw new LogicException('A game in play always has a rule state.');
    }

    private function context(StageId $stage): GameContext
    {
        return GameContext::of(
            $stage,
            $this->seats,
            $this->currentSeat,
            $this->drawLog,
            $this->turnNumber,
            $this->ruleStateInPlay(),
            $this->roomConfig,
        );
    }

    private function assertNotOver(): void
    {
        if (GameStatus::isTerminal($this->status)) {
            throw new GameAlreadyFinishedException;
        }
    }

    private function assertInLobby(): void
    {
        $this->assertNotOver();

        if ($this->status !== GameStatus::LOBBY) {
            throw new GameNotInLobbyException;
        }
    }

    private function assertRunning(): void
    {
        $this->assertNotOver();

        if ($this->status !== GameStatus::RUNNING) {
            throw new GameNotRunningException;
        }
    }

    /** A game in flight is played by the ruleset it was pinned to, and by no other. */
    private function assertPinnedTo(RuleSet $rules): void
    {
        if ($this->ruleSetId !== null && $this->ruleSetId !== $rules->id()) {
            throw RuleSetMismatchException::between($this->ruleSetId, $rules->id());
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
        // The effects belong to the write being recorded and to no other: a write
        // that consults no ruleset has none, and leaving the previous write's
        // behind would project a challenge the table has already answered.
        if (! array_key_exists('effects', $payload)) {
            $this->lastEffects = [];
        }

        $this->lastSequence++;
        $this->pendingMoves[] = Move::of($this->lastSequence, $kind, $actorSeat, $payload);
    }
}
