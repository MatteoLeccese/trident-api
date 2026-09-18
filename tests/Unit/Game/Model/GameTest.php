<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Model;

use LogicException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Exceptions\GameAlreadyFinishedException;
use Src\Game\Domain\Exceptions\GameNotInLobbyException;
use Src\Game\Domain\Exceptions\GameNotRunningException;
use Src\Game\Domain\Exceptions\InvalidSeatOrderException;
use Src\Game\Domain\Exceptions\NicknameTakenException;
use Src\Game\Domain\Exceptions\NoPendingChoiceException;
use Src\Game\Domain\Exceptions\PoolPositionAlreadyTakenException;
use Src\Game\Domain\Exceptions\PoolPositionNotInPoolException;
use Src\Game\Domain\Exceptions\RuleSetMismatchException;
use Src\Game\Domain\Exceptions\SeatNotFoundException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\Move;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Unit\Game\Doubles\CountingRuleSet;
use Tests\Unit\Game\Doubles\HostileRuleSet;
use Throwable;

final class GameTest extends TestCase
{
    /** A literal seed: a test that depends on chance proves nothing. */
    private const SEED = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

    private function open(?ControllerToken $token = null, ?FrozenClock $clock = null, ?SeatRoster $seats = null): Game
    {
        return Game::open(
            GameId::random(),
            JoinCode::generate(),
            $token ?? ControllerToken::generate(),
            $seats ?? SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            $clock ?? FrozenClock::at('2026-09-15 20:00:00'),
            Seed::fromString(self::SEED),
        );
    }

    /** A game in play, with the moves of opening and starting already pulled. */
    private function started(?RuleSet $rules = null): Game
    {
        $game = $this->open();
        $game->start($rules ?? new TridentRuleSet, $this->clock());
        $game->pullMoves();

        return $game;
    }

    private function clock(string $at = '2026-09-15 20:05:00'): FrozenClock
    {
        return FrozenClock::at($at);
    }

    /**
     * @param  callable(): mixed  $call
     * @param  class-string<Throwable>  $expected
     */
    private function refuses(callable $call, string $expected): void
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($expected, $thrown);

            return;
        }

        self::fail("Expected {$expected} and nothing was thrown.");
    }

    public function test_a_new_game_starts_in_the_lobby_at_version_one(): void
    {
        $game = $this->open();

        $this->assertSame(GameStatus::LOBBY, $game->status());
        $this->assertSame(1, $game->version()->value());
    }

    public function test_opening_records_the_moment_it_happened(): void
    {
        $game = $this->open(clock: FrozenClock::at('2026-09-15 20:00:00'));

        $this->assertSame('2026-09-15T20:00:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    public function test_opening_leaves_a_move_in_the_log(): void
    {
        $moves = $this->open()->pullMoves();

        $this->assertCount(1, $moves);
        $this->assertSame(MoveKind::GAME_OPENED, $moves[0]->kind());
        $this->assertSame(1, $moves[0]->sequence());
    }

    public function test_pulling_moves_empties_the_pending_log(): void
    {
        // The repository empties them when persisting them; otherwise they would be written twice.
        $game = $this->open();
        $game->pullMoves();

        $this->assertSame([], $game->pullMoves());
    }

    public function test_renaming_a_seat_changes_the_name(): void
    {
        $game = $this->open();

        $game->renameSeat(SeatNumber::fromInt(2), Nickname::fromString('Bea María'), FrozenClock::at('2026-09-15 20:05:00'));

        $this->assertSame('Bea María', $game->seats()->at(SeatNumber::fromInt(2))->nickname()->value());
    }

    public function test_renaming_bumps_the_version_by_exactly_one(): void
    {
        // The client guard depends on this: incoming === current + 1.
        $game = $this->open();
        $clock = FrozenClock::at('2026-09-15 20:05:00');

        $game->renameSeat(SeatNumber::fromInt(2), Nickname::fromString('Bea María'), $clock);
        $this->assertSame(2, $game->version()->value());

        $game->renameSeat(SeatNumber::fromInt(3), Nickname::fromString('Carolina'), $clock);
        $this->assertSame(3, $game->version()->value());
    }

    public function test_renaming_slides_the_activity_window(): void
    {
        // Expiry is a sliding window, never an absolute clock.
        $game = $this->open(clock: FrozenClock::at('2026-09-15 20:00:00'));

        $game->renameSeat(SeatNumber::fromInt(1), Nickname::fromString('Anita'), FrozenClock::at('2026-09-15 20:45:00'));

        $this->assertSame('2026-09-15T20:45:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    public function test_renaming_leaves_a_move_naming_the_seat_that_changed(): void
    {
        $game = $this->open();
        $game->pullMoves();

        $game->renameSeat(SeatNumber::fromInt(2), Nickname::fromString('Bea María'), FrozenClock::at('2026-09-15 20:05:00'));
        $moves = $game->pullMoves();

        $this->assertCount(1, $moves);
        $this->assertSame(MoveKind::SEAT_RENAMED, $moves[0]->kind());
        $this->assertSame(2, $moves[0]->actorSeat()?->value());
        $this->assertSame(2, $moves[0]->sequence());
    }

    public function test_move_sequence_never_repeats(): void
    {
        // `unique(game_id, seq)` in the database is the concurrency control:
        // two writes with the same expected version cannot both commit.
        $game = $this->open();
        $clock = FrozenClock::at('2026-09-15 20:05:00');

        $game->renameSeat(SeatNumber::fromInt(1), Nickname::fromString('Anita'), $clock);
        $game->renameSeat(SeatNumber::fromInt(2), Nickname::fromString('Bea María'), $clock);

        $sequences = array_map(static fn ($move): int => $move->sequence(), $game->pullMoves());

        $this->assertSame([1, 2, 3], $sequences);
    }

    public function test_a_name_someone_else_has_is_rejected(): void
    {
        $this->expectException(NicknameTakenException::class);

        $this->open()->renameSeat(SeatNumber::fromInt(2), Nickname::fromString('Ana'), FrozenClock::at('2026-09-15 20:05:00'));
    }

    public function test_an_empty_seat_cannot_be_renamed(): void
    {
        $this->expectException(SeatNotFoundException::class);

        $this->open()->renameSeat(SeatNumber::fromInt(9), Nickname::fromString('Zoe'), FrozenClock::at('2026-09-15 20:05:00'));
    }

    public function test_a_finished_game_cannot_be_touched(): void
    {
        $game = $this->open();
        $game->abandon(FrozenClock::at('2026-09-15 21:00:00'));

        $this->expectException(GameAlreadyFinishedException::class);

        $game->renameSeat(SeatNumber::fromInt(1), Nickname::fromString('Anita'), FrozenClock::at('2026-09-15 21:05:00'));
    }

    public function test_a_failed_rename_changes_nothing_at_all(): void
    {
        // Not the version, not the log, not the activity window: a rejected write
        // cannot leave half of its effects behind.
        $game = $this->open(clock: FrozenClock::at('2026-09-15 20:00:00'));
        $game->pullMoves();

        try {
            $game->renameSeat(SeatNumber::fromInt(2), Nickname::fromString('Ana'), FrozenClock::at('2026-09-15 20:45:00'));
            $this->fail('Expected a NicknameTakenException.');
        } catch (NicknameTakenException) {
            // expected
        }

        $this->assertSame(1, $game->version()->value());
        $this->assertSame([], $game->pullMoves());
        $this->assertSame('2026-09-15T20:00:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    // ------------------------------------------------------------- the lobby

    public function test_the_table_writes_its_settings_in_the_lobby(): void
    {
        $game = $this->open();
        $game->pullMoves();

        $game->configureRoom(RoomConfig::fromArray(['challenge.face.0' => 'Say something']), $this->clock());
        $moves = $game->pullMoves();

        $this->assertSame(['challenge.face.0' => 'Say something'], $game->roomConfig()->toArray());
        $this->assertSame(2, $game->version()->value());
        $this->assertCount(1, $moves);
        $this->assertSame(MoveKind::ROOM_CONFIGURED, $moves[0]->kind());
    }

    public function test_writing_the_same_settings_writes_nothing_at_all(): void
    {
        // No version, no move and therefore no broadcast: picking a box up,
        // thinking and putting it back is a gesture, not a change.
        $game = $this->open();
        $config = RoomConfig::fromArray(['challenge.face.0' => 'Say something']);

        $game->configureRoom($config, $this->clock());
        $game->pullMoves();

        $game->configureRoom($config, $this->clock('2026-09-15 20:45:00'));

        $this->assertSame(2, $game->version()->value());
        $this->assertSame([], $game->pullMoves());
        $this->assertSame('2026-09-15T20:05:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    public function test_the_settings_are_resolved_against_the_rulesets_spec_when_play_begins(): void
    {
        // The tolerant reader runs once, at the moment the ruleset is known: a key
        // its spec does not declare is ignored, and every key it does declare is
        // present afterwards, with its default where the table wrote nothing.
        $game = $this->open();
        $rules = new TridentRuleSet;

        $game->configureRoom(RoomConfig::fromArray([
            'challenge.face.0' => 'Say something',
            'nobody.declared.this' => 'ignored',
        ]), $this->clock());

        $game->start($rules, $this->clock());
        $resolved = $game->roomConfig()->toArray();

        $this->assertSame($rules->roomConfigSpec()->keys(), array_keys($resolved));
        $this->assertSame('Say something', $resolved['challenge.face.0']);
        $this->assertArrayNotHasKey('nobody.declared.this', $resolved);
    }

    public function test_the_settings_are_frozen_once_play_has_begun(): void
    {
        $game = $this->started();

        $this->refuses(
            fn () => $game->configureRoom(RoomConfig::fromArray(['challenge.face.0' => 'Too late']), $this->clock()),
            GameNotInLobbyException::class,
        );
    }

    public function test_reordering_renumbers_the_table_by_absolute_permutation(): void
    {
        // The payload is the arrangement the table wants — the seat numbers as
        // they stand now, in the order they will stand — and not "move 3 to 1".
        $game = $this->open();
        $game->pullMoves();

        $game->reorderSeats($this->order([3, 1, 2]), $this->clock());
        $moves = $game->pullMoves();

        $this->assertSame(
            ['Caro', 'Ana', 'Bea'],
            array_column($game->seats()->toArray(), 'nickname'),
        );
        $this->assertSame([1, 2, 3], array_column($game->seats()->toArray(), 'seat'));
        $this->assertSame(2, $game->version()->value());
        $this->assertCount(1, $moves);
        $this->assertSame(MoveKind::SEATS_REORDERED, $moves[0]->kind());
        $this->assertSame([3, 1, 2], $moves[0]->payload()['order']);
    }

    public function test_reordering_keeps_every_seat_its_identity_and_its_roles(): void
    {
        // A role is an opaque string the framework never interprets, and the seat
        // id is the identity of a stored row: renumbering rotates neither.
        $seats = SeatRoster::fromSeats([
            Seat::of(SeatNumber::fromInt(1), Nickname::fromString('Ana')),
            Seat::of(SeatNumber::fromInt(2), Nickname::fromString('Bea'), ['marked']),
            Seat::of(SeatNumber::fromInt(3), Nickname::fromString('Caro')),
        ]);

        $game = $this->open(seats: $seats);
        $before = $game->seats()->at(SeatNumber::fromInt(2));

        $game->reorderSeats($this->order([2, 3, 1]), $this->clock());
        $after = $game->seats()->at(SeatNumber::first());

        $this->assertSame($before->id()->value(), $after->id()->value());
        $this->assertSame(['marked'], $after->roles());
        $this->assertSame('Bea', $after->nickname()->value());
    }

    public function test_the_identity_permutation_writes_nothing_at_all(): void
    {
        // A drag emits one intention and many frames, and putting the table back
        // where it was is not a change: no version, no move, no broadcast.
        $game = $this->open();
        $game->pullMoves();

        $game->reorderSeats($this->order([1, 2, 3]), $this->clock('2026-09-15 20:45:00'));

        $this->assertSame(1, $game->version()->value());
        $this->assertSame([], $game->pullMoves());
        $this->assertSame('2026-09-15T20:00:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    public function test_an_order_that_is_not_a_permutation_of_the_table_is_refused(): void
    {
        $game = $this->open();

        $this->refuses(fn () => $game->reorderSeats($this->order([1, 2]), $this->clock()), InvalidSeatOrderException::class);
        $this->refuses(fn () => $game->reorderSeats($this->order([1, 1, 2]), $this->clock()), InvalidSeatOrderException::class);
        $this->refuses(fn () => $game->reorderSeats($this->order([1, 2, 9]), $this->clock()), SeatNotFoundException::class);

        $this->assertSame(1, $game->version()->value());
        $this->assertSame(['Ana', 'Bea', 'Caro'], array_column($game->seats()->toArray(), 'nickname'));
    }

    public function test_seats_are_renumbered_in_the_lobby_and_nowhere_else(): void
    {
        // `game_moves.actor_seat` is a bare number and not a reference to a
        // person, so a seat renumbered once there is history would make that
        // history name somebody else.
        $game = $this->started();

        $this->refuses(fn () => $game->reorderSeats($this->order([3, 1, 2]), $this->clock()), GameNotInLobbyException::class);
    }

    // ----------------------------------------------------------------- play

    public function test_starting_enters_the_first_stage_with_a_pool_and_a_cursor(): void
    {
        $game = $this->open();
        $rules = new TridentRuleSet;

        $game->start($rules, $this->clock());

        $this->assertSame(GameStatus::RUNNING, $game->status());
        $this->assertSame(TridentRuleSet::ID, $game->ruleSetId());
        $this->assertSame($rules->stages()->first()->value(), $game->stage()?->value());
        $this->assertSame(1, $game->currentSeat()?->value());
        $this->assertSame(49, $game->pool()->count());
        $this->assertSame(1, $game->stageVisits($rules->stages()->first()->value()));
        $this->assertSame(0, $game->turnNumber());
        $this->assertSame(2, $game->version()->value());
    }

    public function test_starting_leaves_one_move_naming_the_ruleset_it_pinned(): void
    {
        $game = $this->open();
        $game->pullMoves();

        $game->start(new TridentRuleSet, $this->clock());
        $moves = $game->pullMoves();

        $this->assertCount(1, $moves);
        $this->assertSame(MoveKind::GAME_STARTED, $moves[0]->kind());
        $this->assertSame(TridentRuleSet::ID, $moves[0]->payload()['rule_set_id']);
        $this->assertNull($moves[0]->actorSeat());
    }

    public function test_a_game_starts_once(): void
    {
        $game = $this->started();

        $this->refuses(fn () => $game->start(new TridentRuleSet, $this->clock()), GameNotInLobbyException::class);
    }

    public function test_a_game_in_flight_refuses_a_ruleset_it_was_not_pinned_to(): void
    {
        // A game is pinned to its ruleset at the moment it starts, so a
        // deployment that swaps which ruleset answers cannot change the meaning
        // of a game that is already on a table.
        $game = $this->started();

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::first(), new HostileRuleSet, $this->clock()),
            RuleSetMismatchException::class,
        );
    }

    public function test_a_draw_is_attributed_to_the_current_seat(): void
    {
        // The draw carries no seat: there is one phone and one controller token,
        // so the server cannot know which human is holding it.
        $game = $this->started();
        $acting = $game->currentSeat();

        $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock());
        $moves = $game->pullMoves();

        $this->assertSame(1, $acting?->value());
        $this->assertCount(1, $moves);
        $this->assertSame(MoveKind::TILE_DRAWN, $moves[0]->kind());
        $this->assertSame($acting->value(), $moves[0]->actorSeat()?->value());
        $this->assertSame(1, $moves[0]->payload()['position']);
        $this->assertNotEmpty($moves[0]->payload()['effects']);
        $this->assertSame(1, $game->turnNumber());
        $this->assertSame(1, $game->drawLog()->all()[0]->seat()->value());
    }

    public function test_a_draw_hands_the_turn_to_the_next_seat_around_the_ring(): void
    {
        $game = $this->started();
        $rules = new TridentRuleSet;
        $stage = $game->stage();

        foreach ([1, 2, 3] as $position) {
            $game->drawTile(PoolPosition::fromInt($position), $rules, $this->clock());
        }

        $this->assertSame(
            $stage?->value(),
            $game->stage()?->value(),
            'This fixture assumes the first three draws do not end the stage.',
        );
        $this->assertSame(
            [1, 2, 3],
            array_map(static fn ($draw): int => $draw->seat()->value(), $game->drawLog()->all()),
        );
        $this->assertSame(1, $game->currentSeat()?->value());
    }

    public function test_a_draw_marks_the_position_and_never_removes_it(): void
    {
        $game = $this->started();

        $game->drawTile(PoolPosition::fromInt(2), new TridentRuleSet, $this->clock());

        $this->assertSame(49, $game->pool()->count());
        // The draw is attributed to the current seat the aggregate holds (TR-11),
        // which is what lets the board show who filled it.
        $this->assertSame([2 => 1], array_map(
            static fn (SeatNumber $seat): int => $seat->value(),
            $game->pool()->takers(),
        ));
    }

    public function test_a_position_outside_the_pool_is_refused(): void
    {
        $game = $this->started();

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::fromInt(50), new TridentRuleSet, $this->clock()),
            PoolPositionNotInPoolException::class,
        );
    }

    public function test_a_position_already_taken_is_refused(): void
    {
        $game = $this->started();
        $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock());

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock()),
            PoolPositionAlreadyTakenException::class,
        );
    }

    public function test_a_refused_draw_changes_nothing_at_all(): void
    {
        // Not the version, not the log, not the pool, not the cursor and not the
        // turn: a rejected write cannot leave half of its effects behind.
        $game = $this->started();
        $version = $game->version()->value();

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::fromInt(50), new TridentRuleSet, $this->clock('2026-09-15 20:45:00')),
            PoolPositionNotInPoolException::class,
        );

        $this->assertSame($version, $game->version()->value());
        $this->assertSame([], $game->pullMoves());
        $this->assertSame([], $game->pool()->takers());
        $this->assertTrue($game->drawLog()->isEmpty());
        $this->assertSame(0, $game->turnNumber());
        $this->assertSame(1, $game->currentSeat()?->value());
        $this->assertSame('2026-09-15T20:05:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    public function test_a_draw_of_a_taken_position_changes_nothing_at_all(): void
    {
        // The other half of the same claim, and the one nothing was asserting:
        // both refusals are raised by `drawTile()` before anything is assigned,
        // and the pool would raise the same exception class later — after the
        // cursor had already moved. The exception class is not the guarantee;
        // the untouched aggregate is.
        $game = $this->started();
        $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock());
        $game->pullMoves();

        $turn = $game->turnNumber();
        $seat = $game->currentSeat()?->value();
        $version = $game->version()->value();
        $taken = $game->pool()->takers();

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock('2026-09-15 20:45:00')),
            PoolPositionAlreadyTakenException::class,
        );

        $this->assertSame($turn, $game->turnNumber());
        $this->assertSame($seat, $game->currentSeat()?->value());
        $this->assertSame($version, $game->version()->value());
        $this->assertSame($taken, $game->pool()->takers());
        $this->assertSame([], $game->pullMoves());
    }

    public function test_a_taken_position_never_reaches_the_ruleset(): void
    {
        // A refused draw is not a turn, so the seam is not consulted for it: a
        // ruleset that counted it would have moved its own state for a tap the
        // aggregate rejected.
        $rules = new CountingRuleSet(new TridentRuleSet);
        $game = $this->started($rules);

        $game->drawTile(PoolPosition::first(), $rules, $this->clock());

        $this->assertSame(1, $rules->tilesDrawn);

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::first(), $rules, $this->clock()),
            PoolPositionAlreadyTakenException::class,
        );

        $this->assertSame(1, $rules->tilesDrawn, 'The ruleset was asked once, for the one draw that happened.');
    }

    public function test_a_game_that_has_not_started_cannot_be_drawn_from(): void
    {
        $game = $this->open();

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock()),
            GameNotRunningException::class,
        );
    }

    public function test_a_finished_game_cannot_be_drawn_from(): void
    {
        $game = $this->started();
        $game->abandon($this->clock('2026-09-15 21:00:00'));

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock()),
            GameAlreadyFinishedException::class,
        );
    }

    public function test_renaming_a_seat_to_the_name_it_already_holds_changes_nothing(): void
    {
        // The guard the other two lobby writes already have. The phone
        // re-submitting the name it is showing — a double tap with no
        // `X-Request-Id`, a keyboard dismissal that re-fires the save — must not
        // inflate the version and wake every television for a state that did not
        // change.
        $game = $this->open();
        $game->pullMoves();
        $version = $game->version()->value();

        $game->renameSeat(SeatNumber::first(), Nickname::fromString('Ana'), $this->clock('2026-09-15 20:45:00'));

        $this->assertSame($version, $game->version()->value());
        $this->assertSame([], $game->pullMoves());
        $this->assertSame('2026-09-15T20:00:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    public function test_a_rename_that_only_changes_the_spelling_is_a_write(): void
    {
        // The comparison is on the roster the rename produced, so a name that
        // differs by one character is a change and a name that differs by none is
        // not: nothing here compares comparison keys.
        $game = $this->open();
        $game->pullMoves();

        $game->renameSeat(SeatNumber::first(), Nickname::fromString('ana'), $this->clock('2026-09-15 20:45:00'));

        $this->assertSame(2, $game->version()->value());
        $this->assertCount(1, $game->pullMoves());
    }

    public function test_a_game_in_play_refuses_to_project_without_its_ruleset(): void
    {
        // A game is pinned to an id and holds no ruleset, so whoever resolves the
        // id hands the object back in. Projecting without it would emit an empty
        // board for a table with 49 tiles on it — a television painting an empty
        // room for a game in progress — so it is a crash and never a silent
        // board.
        $game = $this->started();

        $this->refuses(
            fn () => $game->snapshot(null, 30),
            LogicException::class,
        );
    }

    public function test_a_lobby_projects_perfectly_well_without_a_ruleset(): void
    {
        // The other side of the same guard: a lobby is pinned to nothing, its
        // board is empty because there is no board, and asking for a ruleset it
        // does not have would make reading a lobby depend on a deployment default.
        $snapshot = $this->open()->snapshot(null, 30)->toArray();

        $this->assertSame([], $snapshot['pool']);
        $this->assertNull($snapshot['stage']);
    }

    public function test_answering_a_question_nobody_asked_is_refused(): void
    {
        $game = $this->started();

        $this->refuses(
            fn () => $game->answerChoice('anything', new TridentRuleSet, $this->clock()),
            NoPendingChoiceException::class,
        );
    }

    public function test_a_parked_game_refuses_a_draw_and_keeps_its_cursor(): void
    {
        // While a rule waits for an answer the turn is not over: no position may
        // be turned over, and the seat that has to answer is still the one acting.
        $game = $this->open();
        $rules = new HostileRuleSet;

        $game->start($rules, $this->clock());
        $game->drawTile(PoolPosition::first(), $rules, $this->clock());

        $this->assertSame(GameStatus::AWAITING_CHOICE, $game->status());
        $this->assertSame(1, $game->currentSeat()?->value());
        $this->assertNotNull($game->pendingChoice());

        $this->refuses(
            fn () => $game->drawTile(PoolPosition::fromInt(2), $rules, $this->clock()),
            GameNotRunningException::class,
        );
    }

    public function test_every_write_bumps_the_version_by_exactly_one(): void
    {
        // The client guard depends on it: incoming === current + 1 means apply.
        $game = $this->open();
        $rules = new TridentRuleSet;
        $versions = [$game->version()->value()];

        $game->configureRoom(RoomConfig::fromArray(['challenge.face.0' => 'Say something']), $this->clock());
        $versions[] = $game->version()->value();

        $game->reorderSeats($this->order([2, 3, 1]), $this->clock());
        $versions[] = $game->version()->value();

        $game->start($rules, $this->clock());
        $versions[] = $game->version()->value();

        $game->drawTile(PoolPosition::first(), $rules, $this->clock());
        $versions[] = $game->version()->value();

        $this->assertSame([1, 2, 3, 4, 5], $versions);
    }

    public function test_every_write_appends_exactly_one_move_with_a_unique_sequence(): void
    {
        $game = $this->open();
        $rules = new TridentRuleSet;

        $game->start($rules, $this->clock());
        $game->drawTile(PoolPosition::first(), $rules, $this->clock());
        $game->drawTile(PoolPosition::fromInt(2), $rules, $this->clock());

        $sequences = array_map(static fn (Move $move): int => $move->sequence(), $game->pullMoves());

        $this->assertSame([1, 2, 3, 4], $sequences);
        $this->assertSame($game->lastSequence(), end($sequences));
    }

    public function test_a_write_slides_the_activity_window(): void
    {
        $game = $this->started();

        $game->drawTile(PoolPosition::first(), new TridentRuleSet, $this->clock('2026-09-15 20:45:00'));

        $this->assertSame('2026-09-15T20:45:00+00:00', $game->lastActivityAt()->format(DATE_ATOM));
    }

    public function test_every_kind_the_aggregate_writes_is_a_kind_it_declares(): void
    {
        // The sweep in both directions: every kind this class writes is valid, and
        // `MoveKind::ALL` carries nothing that no write produces. A new write
        // method and its constant land in the same commit.
        $game = $this->open();
        $rules = new HostileRuleSet;

        $game->renameSeat(SeatNumber::first(), Nickname::fromString('Anita'), $this->clock());
        $game->configureRoom(RoomConfig::fromArray([HostileRuleSet::CONFIG_BANNER => 'Anything']), $this->clock());
        $game->reorderSeats($this->order([3, 1, 2]), $this->clock());
        $game->start($rules, $this->clock());
        $game->drawTile(PoolPosition::first(), $rules, $this->clock());
        $game->answerChoice(HostileRuleSet::OPTION_BETA, $rules, $this->clock());
        $game->abandon($this->clock());

        $kinds = array_map(static fn (Move $move): string => $move->kind(), $game->pullMoves());

        foreach ($kinds as $kind) {
            $this->assertTrue(MoveKind::isValid($kind), "'{$kind}' is not a declared move kind.");
        }

        $this->assertSame(MoveKind::ALL, array_values(array_unique($kinds)));
    }

    /**
     * @param  list<int>  $numbers
     * @return list<SeatNumber>
     */
    private function order(array $numbers): array
    {
        return array_map(SeatNumber::fromInt(...), $numbers);
    }

    public function test_it_recognises_the_phone_that_holds_the_controller_token(): void
    {
        $token = ControllerToken::generate();
        $game = $this->open(token: $token);

        $this->assertTrue($game->isControlledBy($token));
        $this->assertFalse($game->isControlledBy(ControllerToken::generate()));
    }
}
