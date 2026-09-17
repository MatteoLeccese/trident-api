<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Infrastructure\Persistence\EloquentGameRepository;
use Src\Shared\Domain\ValueObjects\Version;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\TestCase;

/**
 * The only tests that drive `EloquentGameRepository` against a real database.
 *
 * The in-memory double is deliberately NOT bound here: the container keeps the
 * production wiring, so every assertion below is about rows, indexes and the
 * transaction, not about a test helper.
 */
final class EloquentGameRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private const GAME_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private const OTHER_GAME_ID = '1b4e28ba-2fa1-4d2b-a1a5-1c48d1a5a5a5';

    private function repository(): GameRepository
    {
        return $this->app->make(GameRepository::class);
    }

    /**
     * A saved game with seats 1..3 named Ana, Bea and Caro.
     */
    private function seededGame(): Game
    {
        return $this->seededGameOf(['Ana', 'Bea', 'Caro']);
    }

    /**
     * A saved game whose seats 1..N carry the given names, in order.
     *
     * @param  list<string>  $nicknames
     */
    private function seededGameOf(array $nicknames): Game
    {
        $game = Game::open(
            GameId::fromString(self::GAME_ID),
            JoinCode::fromString('K7QP3M'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), $nicknames)),
            FrozenClock::at('2026-09-16 20:00:00'),
        );

        $this->repository()->save($game);

        return $game;
    }

    /**
     * A roster of seats 1..N carrying the given names, in order.
     *
     * @param  list<string>  $nicknames
     */
    private function rosterOf(array $nicknames): SeatRoster
    {
        $seats = [];

        foreach (array_values($nicknames) as $index => $nickname) {
            $seats[] = Seat::of(SeatNumber::fromInt($index + 1), Nickname::fromString($nickname));
        }

        return SeatRoster::fromSeats($seats);
    }

    /**
     * @return list<string>
     */
    private function seatIdsOf(Game $game): array
    {
        return array_map(
            static fn (Seat $seat): string => $seat->id()->value(),
            $game->seats()->seats(),
        );
    }

    /**
     * The same game carrying a different roster and the next version.
     *
     * `Game` has no write method that reorders a roster, so the aggregate is
     * rebuilt through `reconstitute()`: the repository is what is under test,
     * not the domain's write API.
     */
    private function withRoster(Game $game, SeatRoster $roster, ?string $status = null): Game
    {
        return Game::reconstitute(
            $game->id(),
            $game->joinCode(),
            $game->controllerTokenHash(),
            $status ?? $game->status(),
            $roster,
            $game->version()->next(),
            new DateTimeImmutable('2026-09-16 20:05:00'),
            $game->lastSequence(),
        );
    }

    /**
     * @return list<string>
     */
    private function seatIdsInOrder(): array
    {
        return array_map(
            static fn (mixed $id): string => (string) $id,
            DB::table('game_seats')->orderBy('seat_number')->pluck('id')->all(),
        );
    }

    /**
     * @return list<string>
     */
    private function nicknamesInOrder(): array
    {
        return array_map(
            static fn (mixed $nickname): string => (string) $nickname,
            DB::table('game_seats')->orderBy('seat_number')->pluck('nickname')->all(),
        );
    }

    public function test_the_application_is_wired_to_the_real_repository(): void
    {
        // Defect 4: every other test in the suite binds the in-memory double, so
        // this class is the only place the production implementation runs.
        $this->assertInstanceOf(EloquentGameRepository::class, $this->repository());
    }

    public function test_a_new_game_is_created_with_its_seats_and_its_opening_move(): void
    {
        $game = $this->seededGame();

        $this->assertDatabaseHas('games', ['id' => self::GAME_ID, 'join_code' => 'K7QP3M', 'version' => 1]);
        $this->assertSame(['Ana', 'Bea', 'Caro'], $this->nicknamesInOrder());
        $this->assertDatabaseHas('game_moves', ['game_id' => self::GAME_ID, 'seq' => 1, 'kind' => 'game_opened']);
        $this->assertSame([], $game->pullMoves(), 'Saving consumes the pending move log.');
    }

    public function test_defect_1_two_seats_can_swap_their_nicknames(): void
    {
        // The roster is a set: seat 1 becomes Bea and seat 2 becomes Ana in the
        // same write. Persisting it seat by seat makes the first row claim a name
        // a later row still holds, and `unique(game_id, nickname_key)` rejects it.
        $game = $this->seededGame();

        $this->repository()->save($this->withRoster($game, SeatRoster::fromSeats([
            Seat::of(SeatNumber::fromInt(1), Nickname::fromString('Bea')),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Ana')),
            Seat::of(SeatNumber::fromInt(3), Nickname::fromString('Caro')),
        ])));

        $this->assertSame(['Bea', 'Ana', 'Caro'], $this->nicknamesInOrder());
    }

    public function test_defect_1_a_full_rotation_of_the_roster_persists(): void
    {
        // Every name moves one seat along. No traversal order of the roster makes
        // a row-by-row write legal.
        $game = $this->seededGame();

        $this->repository()->save($this->withRoster($game, SeatRoster::fromSeats([
            Seat::of(SeatNumber::fromInt(1), Nickname::fromString('Caro')),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Ana')),
            Seat::of(SeatNumber::fromInt(3), Nickname::fromString('Bea')),
        ])));

        $this->assertSame(['Caro', 'Ana', 'Bea'], $this->nicknamesInOrder());
    }

    public function test_defect_2_saving_again_keeps_every_seats_primary_key(): void
    {
        // A seat's identity is stable for the life of the game: persisting it is
        // not allowed to mint a new one.
        $game = $this->seededGame();
        $before = $this->seatIdsInOrder();

        $this->repository()->save($this->withRoster($game, $game->seats()));

        $this->assertSame($before, $this->seatIdsInOrder(), 'A save must not rotate a seat primary key.');
    }

    public function test_defect_2_renaming_a_seat_keeps_its_primary_key(): void
    {
        $game = $this->seededGame();
        $before = $this->seatIdsInOrder();

        $renamed = $game->seats()->rename(SeatNumber::fromInt(2), Nickname::fromString('Bea Maria'));
        $this->repository()->save($this->withRoster($game, $renamed));

        $this->assertSame(['Ana', 'Bea Maria', 'Caro'], $this->nicknamesInOrder());
        $this->assertSame($before, $this->seatIdsInOrder(), 'Renaming must not rotate a seat primary key.');
    }

    public function test_defect_3_a_seat_carries_its_identity_and_its_private_state_out_of_the_database(): void
    {
        // `toAggregate()` has to reconstitute both columns, or a write that
        // replaces the rows cannot put back what it did not read.
        $game = $this->seededGame();
        $rowId = (string) DB::table('game_seats')->where('seat_number', 2)->value('id');
        DB::table('game_seats')->where('seat_number', 2)->update([
            'private_state' => json_encode(['hand' => ['6-3']]),
        ]);

        $this->assertTrue(
            class_exists('Src\Game\Domain\ValueObjects\SeatId'),
            'A seat needs its own identity type, so a GameId cannot be passed where a SeatId goes.',
        );
        $this->assertTrue(method_exists(Seat::class, 'id'), 'Seat must expose its SeatId.');
        $this->assertTrue(method_exists(Seat::class, 'privateState'), 'Seat must expose its private state.');

        $seat = $this->repository()->find($game->id())?->seats()->at(SeatNumber::fromInt(2));

        $this->assertInstanceOf(Seat::class, $seat);
        $this->assertSame($rowId, (string) $seat->id());
        $this->assertSame(['hand' => ['6-3']], $seat->privateState());
    }

    public function test_defect_3_private_state_survives_a_read_and_a_write(): void
    {
        // A write that replaces the seat rows must carry this column across. It is
        // the column nothing in the application writes, so nothing else guards it.
        $game = $this->seededGame();
        DB::table('game_seats')->where('seat_number', 2)->update([
            'private_state' => json_encode(['hand' => ['6-3']]),
        ]);

        $reloaded = $this->repository()->find($game->id());
        $this->assertInstanceOf(Game::class, $reloaded);
        $this->repository()->save($this->withRoster($reloaded, $reloaded->seats()));

        // The decoded value and not the stored text: `jsonb` parses what it is
        // given and renders it again, so the bytes are the engine's business and
        // the value is the rule.
        $this->assertSame(
            ['hand' => ['6-3']],
            json_decode((string) DB::table('game_seats')->where('seat_number', 2)->value('private_state'), true),
        );
    }

    public function test_neither_the_seat_id_nor_the_private_state_reaches_the_snapshot(): void
    {
        // The snapshot's shape is a contract with the phone and the television:
        // exactly these three keys, in this order.
        $game = $this->seededGame();
        DB::table('game_seats')->where('seat_number', 2)->update([
            'private_state' => json_encode(['hand' => ['6-3']]),
        ]);

        $reloaded = $this->repository()->find($game->id());
        $this->assertInstanceOf(Game::class, $reloaded);

        foreach ($reloaded->seats()->seats() as $seat) {
            $this->assertSame(['seat', 'nickname', 'roles'], array_keys($seat->toArray()));
        }

        $encoded = (string) json_encode($reloaded->snapshot()->toArray());

        $this->assertStringNotContainsString('private_state', $encoded);
        $this->assertStringNotContainsString('6-3', $encoded);
        $this->assertStringNotContainsString(
            (string) DB::table('game_seats')->where('seat_number', 2)->value('id'),
            $encoded,
        );
    }

    public function test_the_roles_column_round_trips_through_the_repository(): void
    {
        // `roles` is an `array` cast. A raw query-builder insert bypasses casts,
        // so this pins the round trip whatever write path the repository uses.
        $game = $this->seededGame();

        $this->repository()->save($this->withRoster($game, SeatRoster::fromSeats([
            Seat::of(SeatNumber::fromInt(1), Nickname::fromString('Ana'), ['trident']),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Bea'), []),
            Seat::of(SeatNumber::fromInt(3), Nickname::fromString('Caro'), ['dealer', 'trident']),
        ])));

        $this->assertSame(
            ['trident'],
            json_decode((string) DB::table('game_seats')->where('seat_number', 1)->value('roles'), true),
        );
        $this->assertSame(
            ['dealer', 'trident'],
            json_decode((string) DB::table('game_seats')->where('seat_number', 3)->value('roles'), true),
        );
        // A list and never an object, whatever the engine's rendering of it is.
        $this->assertSame(
            '[]',
            json_encode(json_decode((string) DB::table('game_seats')->where('seat_number', 2)->value('roles'))),
        );

        $reloaded = $this->repository()->find($game->id());

        $this->assertInstanceOf(Game::class, $reloaded);
        $this->assertSame(['trident'], $reloaded->seats()->at(SeatNumber::fromInt(1))->roles());
        $this->assertSame([], $reloaded->seats()->at(SeatNumber::fromInt(2))->roles());
        $this->assertSame(['dealer', 'trident'], $reloaded->seats()->at(SeatNumber::fromInt(3))->roles());
    }

    public function test_a_seat_that_leaves_the_roster_leaves_no_row_behind(): void
    {
        // Seats are written as a set. A row the roster no longer holds is not part
        // of the game and must not come back on the next read.
        $game = $this->seededGame();

        $this->repository()->save($this->withRoster($game, SeatRoster::fromSeats([
            Seat::of(SeatNumber::fromInt(1), Nickname::fromString('Ana')),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Bea')),
        ])));

        $this->assertSame(['Ana', 'Bea'], $this->nicknamesInOrder());
        $this->assertSame(['Ana', 'Bea'], array_column(
            (array) $this->repository()->find($game->id())?->seats()->toArray(),
            'nickname',
        ));
    }

    public function test_a_terminal_status_releases_the_join_code(): void
    {
        // Trap 5: `join_code` is a plain unique and NULLs do not collide, so a
        // finished game hands its code back to the pool.
        $game = $this->seededGame();
        $game->abandon(FrozenClock::at('2026-09-16 21:00:00'));

        $this->repository()->save($game);

        $this->assertTrue(GameStatus::isTerminal($game->status()));
        $this->assertNull(DB::table('games')->where('id', self::GAME_ID)->value('join_code'));
        $this->assertNull($this->repository()->findByJoinCode(JoinCode::fromString('K7QP3M')));
    }

    public function test_a_released_code_can_be_taken_by_another_game(): void
    {
        $game = $this->seededGame();
        $game->abandon(FrozenClock::at('2026-09-16 21:00:00'));
        $this->repository()->save($game);

        $next = Game::open(
            GameId::fromString('1b4e28ba-2fa1-4d2b-a1a5-1c48d1a5a5a5'),
            JoinCode::fromString('K7QP3M'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Dani', 'Eva', 'Fran'])),
            FrozenClock::at('2026-09-16 22:00:00'),
        );

        $this->repository()->save($next);

        $this->assertSame(
            $next->id()->value(),
            $this->repository()->findByJoinCode(JoinCode::fromString('K7QP3M'))?->id()->value(),
        );
    }

    public function test_saving_one_game_leaves_another_games_seats_alone(): void
    {
        // The set write deletes the seats OF THE GAME BEING SAVED. An unscoped
        // delete empties every other table in progress, and the next read of those
        // games reconstitutes them with no players at all.
        $first = $this->seededGame();

        $second = Game::open(
            GameId::fromString(self::OTHER_GAME_ID),
            JoinCode::fromString('T4RVXN'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Dani', 'Eva', 'Fran'])),
            FrozenClock::at('2026-09-16 22:00:00'),
        );
        $this->repository()->save($second);

        $this->repository()->save($this->withRoster($second, $this->rosterOf(['Fran', 'Dani', 'Eva'])));

        $this->assertSame(3, DB::table('game_seats')->where('game_id', self::GAME_ID)->count());
        $this->assertSame(
            $this->seatIdsOf($first),
            array_map(
                static fn (mixed $id): string => (string) $id,
                DB::table('game_seats')->where('game_id', self::GAME_ID)->orderBy('seat_number')->pluck('id')->all(),
            ),
        );
        $this->assertSame(
            ['Ana', 'Bea', 'Caro'],
            array_column((array) $this->repository()->find($first->id())?->seats()->toArray(), 'nickname'),
        );
        $this->assertSame(
            ['Fran', 'Dani', 'Eva'],
            array_column((array) $this->repository()->find($second->id())?->seats()->toArray(), 'nickname'),
        );
    }

    public function test_the_postgres_session_runs_in_utc(): void
    {
        // `last_activity_at` is a `timestamptz` written from a string that carries
        // no offset, so the session's zone is what decides which instant is stored.
        // SQLite keeps no zone, so no row this suite writes can pin that: the
        // connection's configuration is the only place it can be asserted.
        $this->assertSame('UTC', config('database.connections.pgsql.timezone'));
    }

    public function test_a_finished_game_can_be_read_back_once_its_code_is_released(): void
    {
        // Trap 6: reconstituting a released code goes through `JoinCode`, so the
        // placeholder has to be a valid Crockford base32 code.
        $game = $this->seededGame();
        $game->abandon(FrozenClock::at('2026-09-16 21:00:00'));
        $this->repository()->save($game);

        $reloaded = $this->repository()->find($game->id());

        $this->assertInstanceOf(Game::class, $reloaded);
        $this->assertSame(GameStatus::ABANDONED, $reloaded->status());
        $this->assertSame(Version::fromInt(2)->value(), $reloaded->version()->value());
        $this->assertSame(['Ana', 'Bea', 'Caro'], array_column($reloaded->seats()->toArray(), 'nickname'));
    }

    public function test_the_roster_survives_a_round_trip_in_order(): void
    {
        // Trap 7: `Seat::toArray()` order is snapshot bytes, so the roster that
        // comes back has to be the one that went in, element for element.
        $game = $this->seededGame();

        $reloaded = $this->repository()->find($game->id());

        $this->assertInstanceOf(Game::class, $reloaded);
        $this->assertSame($game->seats()->toArray(), $reloaded->seats()->toArray());
        $this->assertSame($game->snapshot()->toArray(), $reloaded->snapshot()->toArray());
    }

    public function test_a_rotation_of_a_full_table_of_fifteen_persists_in_one_save(): void
    {
        // Fifteen is the biggest table the roster allows. Every name moves one seat
        // along, so every single row collides with the next one under a row-by-row
        // write.
        $names = array_map(static fn (int $i): string => "Player{$i}", range(1, 15));
        $game = $this->seededGameOf($names);

        $rotated = [];

        foreach (array_keys($names) as $index) {
            $rotated[] = $names[($index + count($names) - 1) % count($names)];
        }

        $this->repository()->save($this->withRoster($game, $this->rosterOf($rotated)));

        $this->assertSame($rotated, $this->nicknamesInOrder());
        $this->assertSame(
            $rotated,
            array_column((array) $this->repository()->find($game->id())?->seats()->toArray(), 'nickname'),
        );
    }

    public function test_a_rename_and_a_reorder_land_in_the_same_save(): void
    {
        // One write carries both: the names move round the table AND one of them
        // changes. There is no intermediate state in which the table is half moved.
        $game = $this->seededGame();

        $this->repository()->save($this->withRoster($game, $this->rosterOf(['Caro', 'Ana Lucia', 'Bea'])));

        $this->assertSame(['Caro', 'Ana Lucia', 'Bea'], $this->nicknamesInOrder());
        $this->assertSame(
            ['caro', 'ana lucia', 'bea'],
            array_map(
                static fn (mixed $key): string => (string) $key,
                DB::table('game_seats')->orderBy('seat_number')->pluck('nickname_key')->all(),
            ),
        );

        $reloaded = $this->repository()->find($game->id());

        $this->assertInstanceOf(Game::class, $reloaded);
        $this->assertSame(['Caro', 'Ana Lucia', 'Bea'], array_column($reloaded->seats()->toArray(), 'nickname'));
    }

    public function test_the_domain_mints_a_seats_identity_before_it_is_saved(): void
    {
        // Identity is assigned where the seat is created, not by the repository:
        // the row carries the id the aggregate already had.
        $game = $this->seededGame();

        $this->assertSame($this->seatIdsOf($game), $this->seatIdsInOrder());
    }

    public function test_a_seat_keeps_its_identity_across_three_consecutive_saves(): void
    {
        $game = $this->seededGame();
        $ids = $this->seatIdsInOrder();

        $unchanged = $this->withRoster($game, $game->seats());
        $this->repository()->save($unchanged);
        $this->assertSame($ids, $this->seatIdsInOrder(), 'A save that changes nothing rotates nothing.');

        $renamed = $this->withRoster(
            $unchanged,
            $unchanged->seats()->rename(SeatNumber::fromInt(2), Nickname::fromString('Bea Maria')),
        );
        $this->repository()->save($renamed);
        $this->assertSame($ids, $this->seatIdsInOrder(), 'A rename carries the identity across.');

        $reloaded = $this->repository()->find($game->id());
        $this->assertInstanceOf(Game::class, $reloaded);
        $this->assertSame($ids, $this->seatIdsOf($reloaded), 'The identity comes back out of the database.');

        $this->repository()->save($this->withRoster($reloaded, $reloaded->seats()));
        $this->assertSame($ids, $this->seatIdsInOrder(), 'A save of a reloaded game rotates nothing.');
        $this->assertSame(['Ana', 'Bea Maria', 'Caro'], $this->nicknamesInOrder());
    }

    public function test_a_seat_added_to_the_table_leaves_the_others_untouched(): void
    {
        $game = $this->seededGame();
        $ids = $this->seatIdsInOrder();

        $grown = SeatRoster::fromSeats([
            ...$game->seats()->seats(),
            Seat::of(SeatNumber::fromInt(4), Nickname::fromString('Dani')),
        ]);

        $this->repository()->save($this->withRoster($game, $grown));

        $this->assertSame(['Ana', 'Bea', 'Caro', 'Dani'], $this->nicknamesInOrder());
        $this->assertSame($ids, array_slice($this->seatIdsInOrder(), 0, 3));
    }

    public function test_a_fresh_seat_stores_an_empty_private_state_object(): void
    {
        // The column holds an object, so an empty private state is `{}` and never
        // `[]`. A bulk insert does not apply the model casts: the encoding is the
        // repository's job.
        $game = $this->seededGame();

        // An object and never a list: `json_decode` gives an object for `{}` and an
        // array for `[]`, so re-encoding tells the two apart on either engine.
        $this->assertSame(
            ['{}', '{}', '{}'],
            array_map(
                static fn (mixed $state): string => (string) json_encode(json_decode((string) $state)),
                DB::table('game_seats')->orderBy('seat_number')->pluck('private_state')->all(),
            ),
        );

        $reloaded = $this->repository()->find($game->id());

        $this->assertInstanceOf(Game::class, $reloaded);

        foreach ($reloaded->seats()->seats() as $seat) {
            $this->assertSame([], $seat->privateState());
        }
    }

    public function test_private_state_survives_the_rename_of_its_own_seat(): void
    {
        $game = $this->seededGame();
        DB::table('game_seats')->where('seat_number', 2)->update([
            'private_state' => json_encode(['hand' => ['6-3']]),
        ]);
        $rowId = (string) DB::table('game_seats')->where('seat_number', 2)->value('id');

        $reloaded = $this->repository()->find($game->id());
        $this->assertInstanceOf(Game::class, $reloaded);

        $this->repository()->save($this->withRoster(
            $reloaded,
            $reloaded->seats()->rename(SeatNumber::fromInt(2), Nickname::fromString('Bea Maria')),
        ));

        $row = DB::table('game_seats')->where('seat_number', 2)->first();

        $this->assertNotNull($row);
        $this->assertSame('Bea Maria', (string) $row->nickname);
        $this->assertSame($rowId, (string) $row->id);
        $this->assertSame(['hand' => ['6-3']], json_decode((string) $row->private_state, true));
        $this->assertSame(
            ['hand' => ['6-3']],
            $this->repository()->find($game->id())?->seats()->at(SeatNumber::fromInt(2))->privateState(),
        );
    }

    public function test_the_whole_roster_is_replaced_within_the_transaction(): void
    {
        // The seats go in as a set: the delete and the insert either both land or
        // neither does. A failed write leaves the table exactly as it was.
        $game = $this->seededGame();
        $before = $this->nicknamesInOrder();

        $clashing = $this->withRoster($game, SeatRoster::fromSeats([
            Seat::of(SeatNumber::fromInt(1), Nickname::fromString('Ana')),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Bea')),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Caro')),
        ]));

        try {
            $this->repository()->save($clashing);
            $this->fail('Two seats with the same number must not be storable.');
        } catch (QueryException) {
            // The unique index is what refuses it; what matters is what is left.
        }

        $this->assertSame($before, $this->nicknamesInOrder());
        $this->assertSame(3, DB::table('game_seats')->where('game_id', self::GAME_ID)->count());
    }

    public function test_a_game_is_found_by_its_code_and_by_its_id(): void
    {
        $game = $this->seededGame();

        $this->assertSame(self::GAME_ID, $this->repository()->find($game->id())?->id()->value());
        $this->assertSame(
            self::GAME_ID,
            $this->repository()->findByJoinCode(JoinCode::fromString('K7QP3M'))?->id()->value(),
        );
        $this->assertNull($this->repository()->find(GameId::fromString('1b4e28ba-2fa1-4d2b-a1a5-1c48d1a5a5a5')));
        $this->assertNull($this->repository()->findByJoinCode(JoinCode::fromString('ZZZZZZ')));
    }
}
