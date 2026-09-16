<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Model;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Infrastructure\Service\FrozenClock;

final class GameSnapshotTest extends TestCase
{
    private ControllerToken $token;

    private function game(): Game
    {
        $this->token = ControllerToken::generate();

        return Game::open(
            GameId::fromString('0f8fad5b-d9cb-469f-a165-70867728950e'),
            JoinCode::fromString('K7QP3M'),
            $this->token,
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-15 20:00:00'),
        );
    }

    public function test_it_has_exactly_the_agreed_shape(): void
    {
        // The frontend types this. One field too many or too few is a broken contract.
        $this->assertSame(
            ['game_id', 'version', 'status', 'join_code', 'seats', 'last_activity_at'],
            array_keys($this->game()->snapshot()->toArray()),
        );
    }

    public function test_it_carries_the_table_as_an_array_of_objects(): void
    {
        $seats = $this->game()->snapshot()->toArray()['seats'];

        $this->assertSame(['seat' => 1, 'nickname' => 'Ana', 'roles' => []], $seats[0]);
        $this->assertSame(['seat' => 2, 'nickname' => 'Bea', 'roles' => []], $seats[1]);
    }

    public function test_seats_are_a_json_list_not_a_positional_map(): void
    {
        // If this serialises as an object, the frontend's `seats.map()` blows up.
        $json = json_encode($this->game()->snapshot()->toArray()['seats']);

        $this->assertIsString($json);
        $this->assertStringStartsWith('[', $json);
    }

    public function test_the_version_travels_as_a_number(): void
    {
        // The client guard compares `incoming.version` against a number.
        $encoded = json_encode($this->game()->snapshot()->toArray());

        $this->assertIsString($encoded);
        $this->assertStringContainsString('"version":1', $encoded);
    }

    public function test_the_instant_is_iso_8601_and_not_a_php_blob(): void
    {
        $this->assertSame(
            '2026-09-15T20:00:00+00:00',
            $this->game()->snapshot()->toArray()['last_activity_at'],
        );
    }

    public function test_it_never_carries_a_credential(): void
    {
        // This is broadcast to every television in the room.
        $game = $this->game();
        $encoded = json_encode($game->snapshot()->toArray());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($this->token->value(), $encoded);
        $this->assertStringNotContainsString($game->controllerTokenHash(), $encoded);

        foreach (['token', 'secret', 'hash', 'takeover'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    public function test_it_reflects_a_rename_at_the_next_version(): void
    {
        $game = $this->game();
        $game->renameSeat(
            SeatNumber::fromInt(2),
            Nickname::fromString('Bea María'),
            FrozenClock::at('2026-09-15 20:05:00'),
        );

        $snapshot = $game->snapshot()->toArray();

        $this->assertSame(2, $snapshot['version']);
        $this->assertSame('Bea María', $snapshot['seats'][1]['nickname']);
        $this->assertSame('2026-09-15T20:05:00+00:00', $snapshot['last_activity_at']);
    }
}
