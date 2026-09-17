<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Persistence;

use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatId;
use Src\Game\Domain\ValueObjects\SeatNumber;
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

    public function findByJoinCode(JoinCode $code): ?Game
    {
        $row = GameModel::with('seats')->where('join_code', $code->value())->first();

        return $row === null ? null : $this->toAggregate($row);
    }

    public function save(Game $game): void
    {
        DB::transaction(function () use ($game): void {
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
                ],
            );

            $this->writeSeats($game);

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
                    'created_at' => $game->lastActivityAt(),
                ]);
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
            (int) (GameMoveModel::where('game_id', $row->id)->max('seq') ?? 0),
        );
    }
}
