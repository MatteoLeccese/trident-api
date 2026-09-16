<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Persistence;

use DateTimeImmutable;
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
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Domain\ValueObjects\Version;

/**
 * The only real implementation of the repository.
 *
 * The row, the seats and the moves go in **within a single transaction**: the old
 * system wrote the game, the players and the cache separately and without a
 * transaction, and that is why they diverged from the very first instant.
 */
final class EloquentGameRepository implements GameRepository
{
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

            foreach ($game->seats()->seats() as $seat) {
                GameSeatModel::updateOrCreate(
                    ['game_id' => $game->id()->value(), 'seat_number' => $seat->number()->value()],
                    [
                        'id' => (string) Str::uuid(),
                        'nickname' => $seat->nickname()->value(),
                        // Its own column, not a functional index: see the migration.
                        'nickname_key' => $seat->nickname()->comparisonKey(),
                        'roles' => $seat->roles(),
                    ],
                );
            }

            // `pullMoves()` empties the log: persisting twice would write twice, and
            // `unique(game_id, seq)` would turn that into a confusing error.
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

    private function toAggregate(GameModel $row): Game
    {
        $seats = [];

        foreach ($row->seats as $seat) {
            $seats[] = Seat::of(
                SeatNumber::fromInt((int) $seat->seat_number),
                Nickname::fromString((string) $seat->nickname),
                array_values((array) ($seat->roles ?? [])),
            );
        }

        return Game::reconstitute(
            GameId::fromString((string) $row->id),
            // A finished game releases its code; when reconstituting it we hand it
            // an arbitrary one, because nobody can join any more.
            JoinCode::fromString((string) ($row->join_code ?? 'ZZZZZZ')),
            (string) $row->controller_token_hash,
            (string) $row->status,
            SeatRoster::fromSeats($seats),
            Version::fromInt((int) $row->version),
            new DateTimeImmutable((string) $row->last_activity_at),
            (int) (GameMoveModel::where('game_id', $row->id)->max('seq') ?? 0),
        );
    }
}
