<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Model;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Exceptions\GameAlreadyFinishedException;
use Src\Game\Domain\Exceptions\NicknameTakenException;
use Src\Game\Domain\Exceptions\SeatNotFoundException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Infrastructure\Service\FrozenClock;

final class GameTest extends TestCase
{
    private function open(?ControllerToken $token = null, ?FrozenClock $clock = null): Game
    {
        return Game::open(
            GameId::random(),
            JoinCode::generate(),
            $token ?? ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            $clock ?? FrozenClock::at('2026-09-15 20:00:00'),
        );
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

    public function test_it_recognises_the_phone_that_holds_the_controller_token(): void
    {
        $token = ControllerToken::generate();
        $game = $this->open(token: $token);

        $this->assertTrue($game->isControlledBy($token));
        $this->assertFalse($game->isControlledBy(ControllerToken::generate()));
    }
}
