<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\Model\PlayState;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\PendingChoice;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\Draw;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatId;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TilePool;
use Src\Shared\Domain\ValueObjects\RequestId;
use Src\Shared\Domain\ValueObjects\Version;

/**
 * The only real implementation of the repository.
 *
 * The row, the seats and the moves go in **within a single transaction**: a read
 * of a game never sees a state whose seats or move log belong to another version.
 *
 * `save()` carries no expected-version guard: `unique(game_id, seq)` refuses two
 * writes that derived the same next sequence number, which is not the same thing
 * as refusing a write built on a state another writer has already replaced.
 *
 * The guard a client asks for lives one layer up, in
 * `Src\Game\Application\Service\GameWriter`, which compares the `expected_version`
 * of the request against the version it has just read and refuses before the
 * aggregate is touched. That is the version the write was built on, because at
 * that point no write method has run: the two numbers only diverge afterwards.
 * It answers a stale phone; what serialises two writers is `findForUpdate()`
 * inside the `transactional()` the writer opens around the whole protocol.
 */
final class EloquentGameRepository implements GameRepository
{
    /**
     * Stands in for the code of a game that has already released it.
     *
     * `JoinCode` accepts nothing else, and a reconstituted terminal game has to
     * hold something. It never reaches a client: the snapshot carries `null` for
     * any terminal status, and no game can be found by a code its row no longer
     * holds.
     */
    private const RELEASED_JOIN_CODE = 'ZZZZZZ';

    public function find(GameId $id): ?Game
    {
        $row = GameModel::with('seats')->find($id->value());

        return $row === null ? null : $this->toAggregate($row);
    }

    /**
     * The same read under `SELECT ... FOR UPDATE`, which holds the row until the
     * surrounding transaction ends. The seats are loaded by a second query that
     * carries no lock, and do not need one: every writer of a game goes through
     * this row first, so holding it is what serialises them.
     *
     * SQLite has no row lock and takes the clause as a no-op, which is why the
     * guarantee is stated against PostgreSQL: it is the only source of truth
     * (R5), and the suite runs on both engines.
     */
    public function findForUpdate(GameId $id): ?Game
    {
        $row = GameModel::where('id', $id->value())->lockForUpdate()->first();

        if ($row === null) {
            return null;
        }

        $row->load('seats');

        return $this->toAggregate($row);
    }

    public function transactional(Closure $work): mixed
    {
        return DB::transaction($work);
    }

    public function findByJoinCode(JoinCode $code): ?Game
    {
        $row = GameModel::with('seats')->where('join_code', $code->value())->first();

        return $row === null ? null : $this->toAggregate($row);
    }

    /**
     * The identities of the games nobody has written to since `$cutoff`.
     *
     * Only the id is read. Loading the aggregates here would deserialise a pool,
     * a roster and a move log for every game the sweep is about to throw away,
     * and the sweep re-reads each one under a lock anyway.
     *
     * Oldest first, so a bounded sweep that cannot reach the end of a backlog
     * always takes the games that have been dead longest.
     *
     * @return list<GameId>
     */
    public function idleSince(DateTimeImmutable $cutoff, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = GameModel::query()
            ->whereNotIn('status', GameStatus::terminal())
            ->where('last_activity_at', '<', $cutoff)
            ->orderBy('last_activity_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return array_map(static fn (string $id): GameId => GameId::fromString($id), $ids);
    }

    /**
     * The game an already-recorded write intention belongs to, and the kind of
     * entry it produced.
     *
     * `game_moves.request_id` is `uuid nullable unique`, so this reads at most
     * one row and the index itself is what refuses a second entry for the same
     * intention. `kind` comes off that same row: no second query and no column
     * that does not already exist.
     *
     * @return array{game: GameId, kind: string}|null
     */
    public function intentionOfRequest(RequestId $requestId): ?array
    {
        $row = GameMoveModel::where('request_id', $requestId->value())->first(['game_id', 'kind']);

        return $row === null
            ? null
            : ['game' => GameId::fromString((string) $row->game_id), 'kind' => (string) $row->kind];
    }

    public function save(Game $game, ?RequestId $requestId = null): void
    {
        DB::transaction(function () use ($game, $requestId): void {
            $play = $game->playState();

            GameModel::updateOrCreate(
                ['id' => $game->id()->value()],
                [
                    // The code is released when the game ends: it is a plain unique,
                    // and in a unique NULLs do not collide with each other.
                    'join_code' => GameStatus::isTerminal($game->status())
                        ? null
                        : $game->joinCode()->value(),
                    'controller_token_hash' => $game->controllerTokenHash(),
                    'status' => $game->status(),
                    'version' => $game->version()->value(),
                    'last_activity_at' => $game->lastActivityAt(),
                    'rule_set_id' => $play->ruleSetId(),
                    // The one place the seed is read. It never reaches a snapshot,
                    // a move payload or an error body (TR-09).
                    'shuffle_seed' => $play->seed()?->value(),
                    'stage' => $play->stage()?->value(),
                    'current_seat' => $play->currentSeat()?->value(),
                    'pool' => self::encodePool($play),
                    'rule_state' => $play->ruleState()?->toArray(),
                    // Cast to an object so that an empty map is stored as `{}` and
                    // not as `[]`: the `array` cast encodes what it is handed, and
                    // the two render differently on both engines.
                    'room_config' => (object) $play->roomConfig()->toArray(),
                    'stage_visits' => (object) $play->stageVisits(),
                    'pending_choice' => $play->pendingChoice()?->toArray(),
                    'finish_reason' => $play->finishReason(),
                ],
            );

            $this->writeSeats($game);

            // The write intention lands on the FIRST entry this save appends and
            // on no other: the unique index holds one row per id, so a save that
            // appends two entries records the intention once. A save that appends
            // none records nothing, which is what keeps a write that changed
            // nothing out of the ledger.
            $stamp = $requestId?->value();

            // `pullMoves()` empties the log, so a second save of the same aggregate
            // writes no move: a sequence number belongs to exactly one row, and
            // `unique(game_id, seq)` is what enforces it.
            foreach ($game->pullMoves() as $move) {
                GameMoveModel::create([
                    'id' => (string) Str::uuid(),
                    'game_id' => $game->id()->value(),
                    'seq' => $move->sequence(),
                    'actor_seat' => $move->actorSeat()?->value(),
                    'kind' => $move->kind(),
                    'payload' => $move->payload(),
                    'request_id' => $stamp,
                    'created_at' => $game->lastActivityAt(),
                ]);

                $stamp = null;
            }
        });
    }

    /**
     * The roster is written **as a set**: every seat of the game being saved is
     * deleted and the whole roster is inserted again, inside the transaction
     * `save()` opened. The delete is scoped to that one game; no other game's
     * table is touched.
     *
     * `game_seats` carries `unique(game_id, seat_number)` and
     * `unique(game_id, nickname_key)`. Writing seat by seat makes the first row
     * whose name changes claim a name that a row further along still holds, so any
     * permutation of the table dies on a unique violation whatever the traversal
     * order. Replacing the set has no intermediate state to collide with, and one
     * path serves a permutation, a rename, a new seat and a seat that leaves.
     */
    private function writeSeats(Game $game): void
    {
        GameSeatModel::where('game_id', $game->id()->value())->delete();

        $rows = [];

        foreach ($game->seats()->seats() as $seat) {
            $rows[] = [
                // Minted by the domain and carried across renames: a save must never
                // rotate the identity of a row.
                'id' => $seat->id()->value(),
                'game_id' => $game->id()->value(),
                'seat_number' => $seat->number()->value(),
                'nickname' => $seat->nickname()->value(),
                // Its own column, not a functional index: see the migration.
                'nickname_key' => $seat->nickname()->comparisonKey(),
                // A bulk insert goes through the query builder, where `$casts` do
                // NOT apply: both jsonb columns are encoded here or the array
                // reaches the driver unbound. An empty private state is `{}` and
                // not `[]`, because the column holds an object.
                'roles' => (string) json_encode($seat->roles()),
                'private_state' => (string) json_encode((object) $seat->privateState()),
            ];
        }

        if ($rows !== []) {
            GameSeatModel::insert($rows);
        }
    }

    private function toAggregate(GameModel $row): Game
    {
        $seats = [];

        foreach ($row->seats as $seat) {
            $seats[] = Seat::reconstitute(
                SeatId::fromString((string) $seat->id),
                SeatNumber::fromInt((int) $seat->seat_number),
                Nickname::fromString((string) $seat->nickname),
                array_values((array) ($seat->roles ?? [])),
                (array) ($seat->private_state ?? []),
            );
        }

        // One pass over the log serves both readers: the last sequence number and
        // the draw history, which has no column of its own.
        $moves = GameMoveModel::where('game_id', $row->id)->orderBy('seq')->get();

        return Game::reconstitute(
            GameId::fromString((string) $row->id),
            JoinCode::fromString((string) ($row->join_code ?? self::RELEASED_JOIN_CODE)),
            (string) $row->controller_token_hash,
            (string) $row->status,
            SeatRoster::fromSeats($seats),
            Version::fromInt((int) $row->version),
            // The column is `timestamptz`, and the model casts it to a Carbon
            // instance that carries its offset. It is converted to UTC rather than
            // formatted to a string, which would drop the offset and move the
            // instant by whatever the session's time zone is.
            $row->last_activity_at->toDateTimeImmutable()->setTimezone(new DateTimeZone('UTC')),
            (int) ($moves->last()->seq ?? 0),
            $this->toPlayState($row, $moves),
        );
    }

    /**
     * The play state of one row.
     *
     * @param  Collection<int, GameMoveModel>  $moves
     */
    private function toPlayState(GameModel $row, Collection $moves): PlayState
    {
        /** @var array{tiles?: list<string>, taken?: list<array{position: int, seat: int}>}|null $pool */
        $pool = $row->pool;
        /** @var array<string, mixed>|null $ruleState */
        $ruleState = $row->rule_state;
        /** @var array{seat: int, prompt_key: string, options: list<string>}|null $choice */
        $choice = $row->pending_choice;

        $visits = [];

        foreach ((array) ($row->stage_visits ?? []) as $stage => $count) {
            $visits[(string) $stage] = (int) $count;
        }

        return PlayState::of(
            $row->rule_set_id === null ? null : (string) $row->rule_set_id,
            $row->shuffle_seed === null ? null : Seed::fromString((string) $row->shuffle_seed),
            $row->stage === null ? null : StageId::fromString((string) $row->stage),
            $row->current_seat === null ? null : SeatNumber::fromInt((int) $row->current_seat),
            $pool === null
                ? TilePool::reconstitute([], [])
                : TilePool::reconstitute(
                    array_map(Tile::fromString(...), array_values($pool['tiles'] ?? [])),
                    self::decodeTakers(array_values($pool['taken'] ?? [])),
                ),
            $this->toDrawLog($moves),
            $ruleState === null ? null : RuleState::fromArray($ruleState),
            RoomConfig::fromArray((array) ($row->room_config ?? [])),
            $choice === null
                ? null
                : PendingChoice::of(
                    SeatNumber::fromInt((int) $choice['seat']),
                    (string) $choice['prompt_key'],
                    array_map(strval(...), array_values($choice['options'])),
                ),
            $row->finish_reason === null ? null : (string) $row->finish_reason,
            $visits,
            $this->toEffects($moves),
        );
    }

    /**
     * The effects of the write this version came from, which have **no column**:
     * they are the `effects` of the last entry of `game_moves`, and every write
     * method appends exactly one entry.
     *
     * They are rebuilt into `Effect` objects rather than handed on as the stored
     * arrays, so the projection serialises them through `Effect::toArray()` on
     * both delivery paths: `jsonb` stores an object's keys in its own order, and
     * a game read back out of a row would otherwise carry the same effects in a
     * different order from the aggregate that was just broadcast.
     *
     * @param  Collection<int, GameMoveModel>  $moves
     * @return list<Effect>
     */
    private function toEffects(Collection $moves): array
    {
        $payload = (array) ($moves->last()?->payload ?? []);
        $effects = [];

        foreach ((array) ($payload['effects'] ?? []) as $effect) {
            $effects[] = Effect::fromArray((array) $effect);
        }

        return $effects;
    }

    /**
     * The draw history, which **has no column**: it is the `tile_drawn` rows of
     * `game_moves`, in sequence order. It is a record and not a score, and it dies
     * with its game (TR-55).
     *
     * @param  Collection<int, GameMoveModel>  $moves
     */
    private function toDrawLog(Collection $moves): DrawLog
    {
        $draws = [];

        foreach ($moves as $move) {
            if ($move->kind !== MoveKind::TILE_DRAWN) {
                continue;
            }

            /** @var array{stage: string, position: int, tile: string} $payload */
            $payload = (array) $move->payload;

            $draws[] = Draw::of(
                (string) $payload['stage'],
                SeatNumber::fromInt((int) $move->actor_seat),
                PoolPosition::fromInt((int) $payload['position']),
                Tile::fromString((string) $payload['tile']),
            );
        }

        return DrawLog::of($draws);
    }

    /**
     * The pool as the column holds it: every face in pool order plus who took
     * what. NULL while no stage is in play, so the column says "this game has no
     * board" instead of "this game has an empty one".
     *
     * `taken` is a **list of objects with an explicit `position`**, ascending by
     * position, and never a map keyed by one. A JSON object keyed by an integer
     * comes back out of `json_decode` and out of `jsonb` with string keys — `"7"`
     * and not `7` — so a pool reloaded from a map would not be the pool that was
     * saved without a cast at every reader. It is also the shape `seats` and the
     * projected `pool` already use, and an empty one is `[]` on both engines
     * rather than the `{}` an empty map would have to be given.
     *
     * The column holds no version and needs none: it is written and read by this
     * one class, in the same deployment, and a game in play is never handed to a
     * reader of another shape.
     *
     * @return array{tiles: list<string>, taken: list<array{position: int, seat: int}>}|null
     */
    private static function encodePool(PlayState $play): ?array
    {
        if ($play->stage() === null) {
            return null;
        }

        $taken = [];

        foreach ($play->pool()->takers() as $position => $seat) {
            $taken[] = ['position' => $position, 'seat' => $seat->value()];
        }

        return [
            'tiles' => array_map(static fn (Tile $tile): string => $tile->value(), $play->pool()->tiles()),
            'taken' => $taken,
        ];
    }

    /**
     * The takers of one stored pool, keyed by position, which is the shape
     * `TilePool` holds them in.
     *
     * @param  list<array{position: int, seat: int}>  $taken
     * @return array<int, SeatNumber>
     */
    private static function decodeTakers(array $taken): array
    {
        $takers = [];

        foreach ($taken as $entry) {
            $takers[(int) $entry['position']] = SeatNumber::fromInt((int) $entry['seat']);
        }

        return $takers;
    }
}
