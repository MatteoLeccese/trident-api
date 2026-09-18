<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Model;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Support\GameProjectorFactory;

final class GameSnapshotTest extends TestCase
{
    /** A literal seed: a run that depends on chance proves nothing. */
    private const SEED = 'ZbVQ8vUCcVNJNCYLbhwSEhz1vmKpSiIk0WBlGzHU7Ss';

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
            Seed::fromString(self::SEED),
        );
    }

    private function project(Game $game): GameSnapshot
    {
        return GameProjectorFactory::make()->project($game);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(Game $game): array
    {
        return $this->project($game)->toArray();
    }

    /** That game, in play, with the first position of the first stage turned over. */
    private function started(): Game
    {
        $game = $this->game();
        $game->start(new TridentRuleSet, FrozenClock::at('2026-09-15 20:10:00'));
        $game->drawTile(PoolPosition::first(), new TridentRuleSet, FrozenClock::at('2026-09-15 20:11:00'));

        return $game;
    }

    public function test_it_has_exactly_the_agreed_shape(): void
    {
        // The frontend types this. One field too many or too few is a broken contract.
        $this->assertSame(
            [
                'game_id',
                'version',
                'status',
                'join_code',
                'stage',
                'current_seat',
                'seats',
                'pool',
                'last_draw',
                'effects',
                'room_config',
                'tv_idle_notice_minutes',
                'last_activity_at',
            ],
            array_keys($this->snapshotOf($this->game())),
        );
    }

    public function test_it_carries_the_table_as_an_array_of_objects(): void
    {
        $seats = $this->snapshotOf($this->game())['seats'];

        $this->assertSame(['seat' => 1, 'nickname' => 'Ana', 'roles' => []], $seats[0]);
        $this->assertSame(['seat' => 2, 'nickname' => 'Bea', 'roles' => []], $seats[1]);
    }

    public function test_seats_are_a_json_list_not_a_positional_map(): void
    {
        // If this serialises as an object, the frontend's `seats.map()` blows up.
        $json = json_encode($this->snapshotOf($this->game())['seats']);

        $this->assertIsString($json);
        $this->assertStringStartsWith('[', $json);
    }

    public function test_the_version_travels_as_a_number(): void
    {
        // The client guard compares `incoming.version` against a number.
        $encoded = json_encode($this->snapshotOf($this->game()));

        $this->assertIsString($encoded);
        $this->assertStringContainsString('"version":1', $encoded);
    }

    public function test_the_instant_is_iso_8601_and_not_a_php_blob(): void
    {
        $this->assertSame(
            '2026-09-15T20:00:00+00:00',
            $this->snapshotOf($this->game())['last_activity_at'],
        );
    }

    public function test_it_never_carries_a_credential(): void
    {
        // This is broadcast to every television in the room.
        $game = $this->game();
        $encoded = json_encode($this->snapshotOf($game));

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($this->token->value(), $encoded);
        $this->assertStringNotContainsString($game->controllerTokenHash(), $encoded);

        foreach (['token', 'secret', 'hash', 'takeover'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    public function test_tr_09_the_shuffle_seed_never_appears_in_the_projection(): void
    {
        // Whoever holds the seed replays `SeededShuffle` and reads every face-down
        // position of both pools from a browser console, which ends the game in
        // silence with nothing in any log to show for it. It is asserted BY VALUE,
        // in the lobby and in play, because the seed has no key to look for.
        foreach ([$this->game(), $this->started()] as $game) {
            $encoded = (string) json_encode($this->snapshotOf($game));

            $this->assertStringNotContainsString(self::SEED, $encoded);
            $this->assertStringNotContainsString('seed', strtolower($encoded));
        }
    }

    public function test_a_game_that_has_not_started_projects_no_stage_no_cursor_and_no_board(): void
    {
        // Null explicit and never an absent key: the frontend's type distinguishes
        // the two, and a key that is sometimes there is a contract nobody can type.
        $snapshot = $this->snapshotOf($this->game());

        $this->assertNull($snapshot['stage']);
        $this->assertNull($snapshot['current_seat']);
        $this->assertSame([], $snapshot['pool']);
    }

    public function test_it_carries_the_stage_and_the_one_seat_that_acts(): void
    {
        $snapshot = $this->snapshotOf($this->started());

        $this->assertSame(TridentRuleSet::STAGE_ELECTION, $snapshot['stage']);
        $this->assertSame(2, $snapshot['current_seat']);
    }

    public function test_tr_07_an_untaken_position_carries_no_face(): void
    {
        // Concealment is a property of the STAGE and never of who is looking: one
        // projection, no per-spectator branch. Position 1 has been turned over and
        // shows its face; every other position shows `tile: null`.
        $pool = $this->snapshotOf($this->started())['pool'];

        $this->assertCount(49, $pool);
        $this->assertTrue($pool[0]['taken']);
        $this->assertIsString($pool[0]['tile']);

        foreach (array_slice($pool, 1) as $position) {
            $this->assertFalse($position['taken']);
            $this->assertNull($position['tile'], 'An untaken position carries no face.');
        }
    }

    public function test_a_game_that_has_not_started_names_no_last_draw(): void
    {
        // Null explicit, like every other absent thing in this payload.
        $this->assertNull($this->snapshotOf($this->game())['last_draw']);
    }

    public function test_the_last_draw_names_the_position_the_face_and_the_seat(): void
    {
        $snapshot = $this->snapshotOf($this->started());

        $this->assertSame(
            ['position' => 1, 'tile' => $snapshot['pool'][0]['tile'], 'seat' => 1],
            $snapshot['last_draw'],
        );
    }

    public function test_the_last_draw_survives_the_write_that_replaces_the_pool(): void
    {
        /*
         * The draw that ends a stage is applied in the same write as the next
         * stage's fresh pool (TR-04, TR-25), so from that version onwards the
         * position that was just turned over is untaken and face down in the pool
         * that arrives with it. Both clients read the face of the tile just drawn,
         * and on this one draw — the one the game is named after (TR-24) — the
         * pool is no longer a record of it.
         *
         * The stage is opaque here: what is asserted is that it changed, never
         * which stage it changed to.
         */
        $game = $this->game();
        $game->start(new TridentRuleSet, FrozenClock::at('2026-09-15 20:10:00'));

        $openingStage = $this->snapshotOf($game)['stage'];
        $position = 0;
        $snapshot = [];

        do {
            $position++;

            $this->assertLessThanOrEqual(49, $position, 'The opening stage never ended.');

            $game->drawTile(
                PoolPosition::fromInt($position),
                new TridentRuleSet,
                FrozenClock::at('2026-09-15 20:11:00'),
            );

            $snapshot = $this->snapshotOf($game);
        } while ($snapshot['stage'] === $openingStage);

        // The pool has forgotten the draw: it is the next stage's.
        $this->assertFalse($snapshot['pool'][$position - 1]['taken']);
        $this->assertNull($snapshot['pool'][$position - 1]['tile']);

        // This field has not.
        $this->assertSame($position, $snapshot['last_draw']['position']);
        $this->assertIsString($snapshot['last_draw']['tile']);
        $this->assertSame(($position - 1) % 3 + 1, $snapshot['last_draw']['seat']);
    }

    public function test_the_board_is_positional_with_an_explicit_position(): void
    {
        $pool = $this->snapshotOf($this->started())['pool'];

        $this->assertSame([1, 2, 3], array_column(array_slice($pool, 0, 3), 'position'));
        $this->assertSame(['position', 'tile', 'taken', 'seat', 'on_board'], array_keys($pool[0]));

        $json = json_encode($pool);

        $this->assertIsString($json);
        $this->assertStringStartsWith('[', $json);
    }

    public function test_the_board_names_the_seat_that_took_each_position(): void
    {
        // What the television is for: from a sofa you see the board filling up
        // and WHO filled it, without counting a pip. The taker is the
        // `current_seat` of the draw (TR-11), so it follows the ring.
        $game = $this->game();
        $game->start(new TridentRuleSet, FrozenClock::at('2026-09-15 20:10:00'));

        for ($position = 1; $position <= 3; $position++) {
            $game->drawTile(
                PoolPosition::fromInt($position),
                new TridentRuleSet,
                FrozenClock::at('2026-09-15 20:1'.($position + 1).':00'),
            );
        }

        $pool = $this->snapshotOf($game)['pool'];

        $this->assertSame([1, 2, 3], array_column(array_slice($pool, 0, 3), 'seat'));
        $this->assertNull($pool[3]['seat'], 'A position nobody took names nobody.');
        $this->assertSame([true, true, true, false], array_column(array_slice($pool, 0, 4), 'taken'));
    }

    public function test_the_setting_the_table_chose_decides_whether_a_taken_position_stays_on_the_board(): void
    {
        // TR-52 as a thing that happens on a screen. The decision is resolved
        // here, in the projection, and reaches the client as a framework flag: no
        // client builds the key of the setting, and no client names the stage it
        // belongs to.
        $boards = [];

        foreach (['keep', 'remove'] as $choice) {
            $game = $this->game();
            $game->configureRoom(
                RoomConfig::fromArray(['drawn_tiles.election' => $choice]),
                FrozenClock::at('2026-09-15 20:05:00'),
            );
            $game->start(new TridentRuleSet, FrozenClock::at('2026-09-15 20:10:00'));
            $game->drawTile(PoolPosition::first(), new TridentRuleSet, FrozenClock::at('2026-09-15 20:11:00'));

            $boards[$choice] = $this->snapshotOf($game)['pool'];
        }

        $this->assertNotSame($boards['keep'], $boards['remove'], 'The setting has to do something.');
        $this->assertTrue($boards['keep'][0]['on_board']);
        $this->assertFalse($boards['remove'][0]['on_board']);

        // And it changes nothing else: same face, same taker, still taken, and a
        // position nobody has touched is on the board under both values, so the
        // grid keeps its geometry either way.
        $this->assertSame($boards['keep'][0]['tile'], $boards['remove'][0]['tile']);
        $this->assertSame($boards['keep'][0]['seat'], $boards['remove'][0]['seat']);
        $this->assertTrue($boards['remove'][0]['taken']);
        $this->assertTrue($boards['keep'][1]['on_board']);
        $this->assertTrue($boards['remove'][1]['on_board']);
    }

    public function test_the_room_configuration_travels_frozen_and_resolved(): void
    {
        // It is resolved against the ruleset's own spec when play begins, so every
        // declared key is present and the client applies no defaults of its own.
        $config = (array) $this->snapshotOf($this->started())['room_config'];

        $this->assertSame(new TridentRuleSet()->roomConfigSpec()->keys(), array_keys($config));
        $this->assertNotSame([], $config);
    }

    public function test_the_room_configuration_is_projected_in_a_fixed_key_order(): void
    {
        // The two delivery paths have to agree byte for byte, and one of them
        // comes out of a `jsonb` column, which keeps an object's keys in its own
        // order — by length, then by bytes. The order of a map is not information,
        // so the projection imposes one instead of carrying storage's.
        $config = (array) $this->snapshotOf($this->started())['room_config'];
        $keys = array_keys($config);
        $sorted = $keys;

        sort($sorted);

        $this->assertSame($sorted, $keys);
    }

    public function test_an_empty_room_configuration_is_a_json_object_and_not_a_list(): void
    {
        // A lobby has written nothing yet. `[]` and `{}` are different types to the
        // client, and a map that arrives as a list breaks a keyed lookup.
        $encoded = json_encode($this->snapshotOf($this->game()));

        $this->assertIsString($encoded);
        $this->assertStringContainsString('"room_config":{}', $encoded);
    }

    public function test_the_television_idle_notice_is_a_deployment_value_outside_the_room_configuration(): void
    {
        // The table does not choose it, so it does not live in `room_config`; it
        // travels in the snapshot because a television arrives by JoinCode and
        // never saw the creation response.
        $snapshot = $this->snapshotOf($this->game());

        $this->assertSame(GameProjectorFactory::TV_IDLE_NOTICE_MINUTES, $snapshot['tv_idle_notice_minutes']);
        $this->assertArrayNotHasKey('tv_idle_notice_minutes', (array) $snapshot['room_config']);
    }

    public function test_it_reflects_a_rename_at_the_next_version(): void
    {
        $game = $this->game();
        $game->renameSeat(
            SeatNumber::fromInt(2),
            Nickname::fromString('Bea María'),
            FrozenClock::at('2026-09-15 20:05:00'),
        );

        $snapshot = $this->snapshotOf($game);

        $this->assertSame(2, $snapshot['version']);
        $this->assertSame('Bea María', $snapshot['seats'][1]['nickname']);
        $this->assertSame('2026-09-15T20:05:00+00:00', $snapshot['last_activity_at']);
    }
}
