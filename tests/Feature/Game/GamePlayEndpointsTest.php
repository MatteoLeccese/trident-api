<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Infrastructure\Http\Controllers\GameController;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Src\Shared\Infrastructure\Service\SystemClock;
use Tests\Doubles\InMemoryGameRepository;
use Tests\TestCase;

/**
 * The write routes of a game in play, through the real controller, the real
 * handlers and the real ruleset.
 *
 * Every assertion here is about the framework's own decisions — the status
 * allows the write, there is a current seat, the position exists and is not
 * taken — and never about a rule: which stage opens a game and which tile ends
 * one are `trident.v1`'s, and its own test carries them by number.
 */
final class GamePlayEndpointsTest extends TestCase
{
    private const GAME_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    /** A literal shuffle seed, so that position one holds the same tile every run. */
    private const SEED = 'ZbVQ8vUCcVNJNCYLbhwSEhz1vmKpSiIk0WBlGzHU7Ss';

    private InMemoryGameRepository $games;

    private string $gameId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games = new InMemoryGameRepository;
        $this->app->instance(GameRepository::class, $this->games);

        $token = ControllerToken::generate();

        // Opened here and not through `POST /api/v1/games` so that the board is
        // the same board on every run: a draw tested on chance is a test that
        // turns red once every forty-nine times, when position one happens to
        // hold the tile that ends the stage.
        $game = Game::open(
            GameId::fromString(self::GAME_ID),
            JoinCode::fromString('K7QP3M'),
            $token,
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-17 20:00:00'),
            Seed::fromString(self::SEED),
        );

        $this->games->save($game);

        $this->gameId = self::GAME_ID;
        $this->token = $token->value();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function write(string $method, string $path, array $body = [], ?string $token = null): TestResponse
    {
        return $this->json($method, "/api/v1/games/{$this->gameId}{$path}", $body, [
            'X-Trident-Controller-Token' => $token ?? $this->token,
        ]);
    }

    private function start(): TestResponse
    {
        return $this->write('POST', '/start');
    }

    /**
     * The board of a fresh game of this same table, one position turned over,
     * with the election's drawn-tiles setting written to the given value.
     *
     * A game of its own for each value: a lobby's settings are frozen when play
     * begins, so the same game cannot be played twice with two of them.
     *
     * @return list<array<string, mixed>>
     */
    private function boardAfterOneDrawWith(string $choice): array
    {
        $token = ControllerToken::generate();

        $game = Game::open(
            GameId::random(),
            // Its own code: two lobbies of the same table are two rows, and a code
            // addresses exactly one of them.
            JoinCode::generate(),
            $token,
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-17 20:00:00'),
            Seed::fromString(self::SEED),
        );

        $this->games->save($game);

        $this->gameId = $game->id()->value();
        $this->token = $token->value();

        $this->write('POST', '/room-config', [
            'room_config' => [TridentRuleSet::drawnTilesKey(TridentRuleSet::STAGE_ELECTION) => $choice],
        ])->assertOk();
        $this->start()->assertOk();

        /** @var list<array<string, mixed>> $pool */
        $pool = (array) $this->write('POST', '/pool/1/draw')->assertOk()->json('data.pool');

        return $pool;
    }

    // ---------------------------------------------------------------- start

    public function test_starting_a_game_pins_its_ruleset_and_deals_the_first_pool(): void
    {
        $data = (array) $this->start()->assertOk()->json('data');

        $this->assertSame(GameStatus::RUNNING, $data['status']);
        $this->assertSame(2, $data['version']);
        $this->assertSame(TridentRuleSet::STAGE_ELECTION, $data['stage']);
        $this->assertSame(1, $data['current_seat']);
        $this->assertCount(49, $data['pool']);
    }

    public function test_the_dealt_board_reaches_the_phone_face_down(): void
    {
        // Hidden by stage and never by viewer: the phone is passed around the
        // table, so whoever holds it reads the same bytes as the television.
        $pool = (array) $this->start()->assertOk()->json('data.pool');

        foreach ($pool as $index => $position) {
            $this->assertSame($index + 1, $position['position']);
            $this->assertFalse($position['taken']);
            $this->assertNull($position['tile'], 'An untaken position carries an explicit null face.');
        }
    }

    public function test_starting_freezes_the_table_settings_with_every_declared_key(): void
    {
        // The one resolution point of documentation/conventions/room-config.md: a
        // lobby holds what the table wrote, and play begins with every key the
        // spec declares present.
        $ruleSet = new TridentRuleSet;
        $declared = $ruleSet->roomConfigSpec()->keys();
        sort($declared);

        $lobby = (array) $this->write('GET', '')->assertOk()->json('data.room_config');
        $running = (array) $this->start()->assertOk()->json('data.room_config');

        $this->assertSame([], $lobby);
        $this->assertSame(
            $declared,
            array_keys($running),
            'Every declared setting is present once play has begun.',
        );
    }

    public function test_a_game_cannot_be_started_twice(): void
    {
        $this->start()->assertOk();

        $this->start()
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_not_in_lobby');
    }

    public function test_an_unknown_game_cannot_be_started(): void
    {
        $this->json('POST', '/api/v1/games/1b4e28ba-2fa1-4d2b-a1a5-1c48d1a5a5a5/start', [], [
            'X-Trident-Controller-Token' => $this->token,
        ])->assertStatus(404)->assertJsonPath('error', 'game_not_found');
    }

    // ----------------------------------------------------------------- draw

    public function test_turning_a_position_over_marks_it_and_publishes_its_face(): void
    {
        $this->start()->assertOk();

        $data = (array) $this->write('POST', '/pool/1/draw')->assertOk()->json('data');

        $this->assertSame(3, $data['version']);
        $this->assertSame(
            TridentRuleSet::STAGE_ELECTION,
            $data['stage'],
            'This seed does not put the tile that ends the stage at position one.',
        );
        $this->assertTrue($data['pool'][0]['taken']);
        $this->assertIsString($data['pool'][0]['tile'], 'A taken position shows its face to everybody at once.');
    }

    public function test_the_board_names_the_seat_that_turned_each_position_over(): void
    {
        // The draw carries no seat (TR-11), so the name on a filled position is
        // the cursor the aggregate held: the television shows who filled the board
        // without anybody counting a pip.
        $this->start()->assertOk();
        $this->write('POST', '/pool/1/draw')->assertOk();

        $pool = (array) $this->write('POST', '/pool/2/draw')->assertOk()->json('data.pool');

        $this->assertSame(1, $pool[0]['seat']);
        $this->assertSame(2, $pool[1]['seat']);
        $this->assertNull($pool[2]['seat'], 'A position nobody took names nobody.');
    }

    public function test_the_drawn_tiles_setting_decides_whether_the_board_keeps_what_it_takes(): void
    {
        // The setting the author asked for, doing something on a screen (TR-52).
        // Both values are played through the real routes on the same seed, so the
        // only difference between the two boards is the one the table chose.
        $keep = $this->boardAfterOneDrawWith(TridentRuleSet::DRAWN_TILES_KEEP);
        $remove = $this->boardAfterOneDrawWith(TridentRuleSet::DRAWN_TILES_REMOVE);

        $this->assertNotSame($keep, $remove, 'The setting reached no screen.');
        $this->assertTrue($keep[0]['on_board']);
        $this->assertFalse($remove[0]['on_board']);

        // And nothing else moved: the same face, the same taker, still taken, and
        // every untaken position still on the board, so the grid keeps its shape.
        $this->assertSame($keep[0]['tile'], $remove[0]['tile']);
        $this->assertSame($keep[0]['seat'], $remove[0]['seat']);
        $this->assertTrue($remove[0]['taken']);
        $this->assertSame(
            array_column(array_slice($keep, 1), 'on_board'),
            array_column(array_slice($remove, 1), 'on_board'),
        );
    }

    public function test_a_taken_position_cannot_be_turned_over_again(): void
    {
        $this->start()->assertOk();
        $this->write('POST', '/pool/1/draw')->assertOk();

        $this->write('POST', '/pool/1/draw')
            ->assertStatus(422)
            ->assertJsonPath('error', 'pool_position_already_taken');
    }

    public function test_a_non_numeric_position_is_a_404_from_the_route_constraint(): void
    {
        // The first of the two failure modes of this route, and a different path
        // from the second: `whereNumber` restricts the segment to digits, so this
        // request matches no route at all and never reaches a handler.
        $this->start()->assertOk();

        $this->write('POST', '/pool/abc/draw')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_a_numeric_position_outside_the_pool_is_a_422_from_the_handler(): void
    {
        // The second failure mode. It does reach the handler, so the answer names
        // the refusal a client is entitled to get wrong.
        $this->start()->assertOk();

        $this->write('POST', '/pool/50/draw')
            ->assertStatus(422)
            ->assertJsonPath('error', 'pool_position_not_in_pool');
    }

    public function test_position_zero_is_refused_by_the_handler_and_not_by_the_route(): void
    {
        // `[0-9]+` matches `0`, so the route hands it over: seats and positions
        // are 1-based everywhere, forever.
        $this->start()->assertOk();

        $this->write('POST', '/pool/0/draw')
            ->assertStatus(422)
            ->assertJsonPath('error', 'pool_position_not_in_pool');
    }

    public function test_a_position_with_a_leading_zero_is_not_a_position(): void
    {
        $this->start()->assertOk();

        $this->write('POST', '/pool/01/draw')
            ->assertStatus(422)
            ->assertJsonPath('error', 'pool_position_not_in_pool');
    }

    public function test_nothing_can_be_drawn_before_the_game_starts(): void
    {
        $this->write('POST', '/pool/1/draw')
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_not_running');
    }

    // ---------------------------------------------------------- room-config

    public function test_the_table_writes_its_settings_and_reads_them_back(): void
    {
        $key = TridentRuleSet::challengeKey(0);

        $saved = $this->write('POST', '/room-config', ['room_config' => [$key => 'Ana picks someone']])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        // Read out of the decoded map and never through a JSON path: the keys are
        // dotted, which is what makes a path expression address the wrong thing.
        $this->assertSame(['Ana picks someone'], [((array) $saved->json('data.room_config'))[$key] ?? null]);
    }

    public function test_the_lobby_stores_what_was_written_and_not_what_it_resolves_to(): void
    {
        $key = TridentRuleSet::drawnTilesKey(TridentRuleSet::STAGE_MAIN);

        $stored = (array) $this->write('POST', '/room-config', [
            'room_config' => [$key => TridentRuleSet::DRAWN_TILES_KEEP],
        ])->assertOk()->json('data.room_config');

        $this->assertSame([$key => TridentRuleSet::DRAWN_TILES_KEEP], $stored);
    }

    public function test_a_setting_this_game_does_not_declare_is_refused_naming_it(): void
    {
        // Never discarded in silence: somebody typed something and pressed save.
        $this->write('POST', '/room-config', ['room_config' => ['sound.volume' => 'loud']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'room_config_key_unknown')
            ->assertJsonPath('data.keys', ['sound.volume']);
    }

    public function test_a_malformed_key_is_refused_as_one_this_game_does_not_declare(): void
    {
        $this->write('POST', '/room-config', ['room_config' => ['Not A Key' => 'x']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'room_config_key_unknown');
    }

    public function test_a_value_a_declared_setting_does_not_accept_is_refused_naming_it(): void
    {
        $key = TridentRuleSet::challengeKey(1);

        $this->write('POST', '/room-config', [
            'room_config' => [$key => str_repeat('x', TridentRuleSet::CHALLENGE_MAX_LENGTH + 1)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'room_config_value_invalid')
            ->assertJsonPath('data.keys', [$key]);
    }

    public function test_an_option_outside_the_closed_set_is_refused_and_never_coerced(): void
    {
        $key = TridentRuleSet::drawnTilesKey(TridentRuleSet::STAGE_ELECTION);

        $this->write('POST', '/room-config', ['room_config' => [$key => 'sometimes']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'room_config_value_invalid');
    }

    public function test_settings_that_are_not_an_object_are_a_validation_error(): void
    {
        $this->write('POST', '/room-config', ['room_config' => 'everything on'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_an_edit_that_changes_nothing_raises_no_version(): void
    {
        // Picking a box up, thinking, and putting it back is a common gesture: the
        // version rises only when something really changed, so no television is
        // woken up for it.
        $key = TridentRuleSet::challengeKey(2);
        $settings = ['room_config' => [$key => 'Everyone left of Ana']];

        $this->write('POST', '/room-config', $settings)->assertOk()->assertJsonPath('data.version', 2);
        $this->write('POST', '/room-config', $settings)->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_a_table_can_empty_a_challenge_and_read_it_back_empty(): void
    {
        // An empty value is legal and means "this face announces nothing"
        // (documentation/conventions/room-config.md). Laravel's global conversion
        // of "" to null would make the one gesture the convention grants a table
        // come back as `room_config_value_invalid`, which reads as "you typed
        // something illegal" for typing nothing at all.
        $key = TridentRuleSet::challengeKey(4);

        $this->write('POST', '/room-config', ['room_config' => [$key => 'Nobody moves']])->assertOk();

        $cleared = (array) $this->write('POST', '/room-config', ['room_config' => [$key => '']])
            ->assertOk()
            ->json('data.room_config');

        $this->assertSame('', $cleared[$key] ?? null);
    }

    public function test_a_table_can_put_every_setting_back_to_its_default(): void
    {
        // What is stored is what was submitted, a replacement and not a merge, so
        // `{}` is exactly how a table says "all defaults". `required` counts an
        // empty object as missing and would leave no way to say it.
        $key = TridentRuleSet::challengeKey(4);

        $this->write('POST', '/room-config', ['room_config' => [$key => 'Nobody moves']])->assertOk();

        $cleared = (array) $this->write('POST', '/room-config', ['room_config' => []])
            ->assertOk()
            ->json('data.room_config');

        $this->assertSame([], $cleared);
    }

    public function test_settings_that_are_absent_altogether_are_still_a_validation_error(): void
    {
        // `present` is not `nullable`: an empty object is a submission, a missing
        // key is a malformed request.
        $this->write('POST', '/room-config', [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_the_settings_are_frozen_once_play_has_begun(): void
    {
        $this->start()->assertOk();

        $this->write('POST', '/room-config', ['room_config' => [TridentRuleSet::challengeKey(3) => 'Too late']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_not_in_lobby');
    }

    // ----------------------------------------------------------- seats/order

    public function test_the_table_can_be_renumbered_in_the_lobby(): void
    {
        $seats = (array) $this->write('PUT', '/seats/order', ['order' => [3, 1, 2]])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->json('data.seats');

        $this->assertSame(['Caro', 'Ana', 'Bea'], array_column($seats, 'nickname'));
        $this->assertSame([1, 2, 3], array_column($seats, 'seat'));
    }

    public function test_the_identity_permutation_changes_nothing(): void
    {
        // The one guarantee this payload can honestly make on its own: it
        // renumbers the very domain it addresses, so a repeat is not a no-op and
        // `expected_version` plus `X-Request-Id` are what refuse one.
        $this->write('PUT', '/seats/order', ['order' => [1, 2, 3]])
            ->assertOk()
            ->assertJsonPath('data.version', 1);
    }

    public function test_an_order_that_does_not_name_every_seat_is_refused(): void
    {
        $this->write('PUT', '/seats/order', ['order' => [1, 2]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'seat_order_invalid');
    }

    public function test_an_order_that_names_a_seat_twice_is_refused(): void
    {
        $this->write('PUT', '/seats/order', ['order' => [1, 1, 2]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'seat_order_invalid');
    }

    public function test_an_order_that_names_a_seat_nobody_holds_is_refused(): void
    {
        // The roster asks itself for that seat before it builds anything, so the
        // refusal is the one that names the real problem: there is no seat 9.
        $this->write('PUT', '/seats/order', ['order' => [1, 2, 9]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'seat_not_found');
    }

    public function test_a_seat_number_below_one_is_a_validation_error(): void
    {
        $this->write('PUT', '/seats/order', ['order' => [0, 1, 2]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_the_table_cannot_be_renumbered_once_play_has_begun(): void
    {
        // `game_moves.actor_seat` is a bare number and not a reference to a
        // person: renumbering once there is history makes that history name
        // somebody else.
        $this->start()->assertOk();

        $this->write('PUT', '/seats/order', ['order' => [3, 1, 2]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_not_in_lobby');
    }

    public function test_renumbering_is_throttled_harder_than_the_rest_of_the_api(): void
    {
        // A drag emits one intention and many frames, and this is the route the
        // frames land on.
        $route = Route::getRoutes()->getByAction(GameController::class.'@reorderSeats');

        $this->assertNotNull($route);
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    // ------------------------------------------------------------ play-again

    public function test_a_rematch_opens_a_new_game_with_the_same_table(): void
    {
        $previous = (array) $this->write('GET', '')->assertOk()->json('data');

        $created = $this->write('POST', '/play-again')->assertCreated();
        $next = (array) $created->json('data.game');

        $this->assertNotSame($previous['game_id'], $next['game_id']);
        $this->assertNotSame($previous['join_code'], $next['join_code']);
        $this->assertSame(GameStatus::LOBBY, $next['status']);
        $this->assertSame(1, $next['version']);
        $this->assertSame(
            array_column($previous['seats'], 'nickname'),
            array_column($next['seats'], 'nickname'),
            'The same names in the same seats.',
        );
    }

    public function test_a_rematch_carries_the_table_settings_across(): void
    {
        $key = TridentRuleSet::challengeKey(4);

        $this->write('POST', '/room-config', ['room_config' => [$key => 'Nobody moves']])->assertOk();

        $carried = (array) $this->write('POST', '/play-again')->assertCreated()->json('data.game.room_config');

        $this->assertSame(['Nobody moves'], [$carried[$key] ?? null]);
    }

    public function test_a_rematch_closes_the_table_it_came_from(): void
    {
        // The reply rotates the controller cookie, so the game it came from can
        // never be written to again: leaving it open would strand it, holding a
        // join code nobody can play, until it expired.
        $before = (array) $this->write('GET', '')->assertOk()->json('data');

        $this->write('POST', '/play-again')->assertCreated();

        $after = (array) $this->write('GET', '')->assertOk()->json('data');

        $this->assertSame(GameStatus::LOBBY, $before['status']);
        $this->assertSame(GameStatus::ABANDONED, $after['status']);
        $this->assertNull($after['join_code'], 'And it gives its code back.');
        $this->assertSame($before['version'] + 1, $after['version']);
    }

    public function test_a_rematch_of_a_game_in_play_closes_it_too(): void
    {
        // The mis-tap the cookie rotation makes unrecoverable: turn twenty of a
        // running game, somebody hits play-again. The table it came from ends
        // where it stood instead of surviving as a game with no writer.
        $this->write('POST', '/start')->assertOk();
        $this->write('POST', '/pool/1/draw')->assertOk();

        $this->write('POST', '/play-again')->assertCreated();

        $closed = (array) $this->write('GET', '')->assertOk()->json('data');

        $this->assertSame(GameStatus::ABANDONED, $closed['status']);
        $this->assertNull($closed['join_code']);
    }

    public function test_a_rematch_of_a_game_in_play_carries_only_what_the_table_wrote(): void
    {
        // `start()` resolves the stored map through the spec, so a game in play
        // holds every declared key. Carrying that across would record today's
        // placeholder texts (TR-51) as a choice the next table made, and a chain
        // of rematches would keep them for ever.
        $this->write('POST', '/start')->assertOk();

        $rematch = (array) $this->write('POST', '/play-again')->assertCreated()->json('data.game');

        $this->assertSame([], (array) $rematch['room_config'], 'A table that configured nothing configures nothing.');
        $this->assertSame(1, $rematch['version'], 'And that is not a write, so it is version one.');
    }

    public function test_a_rematch_of_a_game_in_play_still_carries_what_the_table_did_write(): void
    {
        $key = TridentRuleSet::challengeKey(4);

        $this->write('POST', '/room-config', ['room_config' => [$key => 'Nobody moves']])->assertOk();
        $this->write('POST', '/start')->assertOk();

        $rematch = (array) $this->write('POST', '/play-again')->assertCreated()->json('data.game');

        $this->assertSame(['Nobody moves'], [((array) $rematch['room_config'])[$key] ?? null]);
        $this->assertSame(
            [$key],
            array_keys((array) $rematch['room_config']),
            'Only that one key: the rest are the ruleset\'s defaults, not the table\'s words.',
        );
    }

    public function test_a_rematch_hands_back_a_new_controller_token(): void
    {
        $token = $this->write('POST', '/play-again')->assertCreated()->json('data.controller_token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        $this->assertNotSame($this->token, $token, 'The cookie the BFF writes rotates with the game.');
    }

    public function test_the_rematch_snapshot_itself_never_carries_the_token(): void
    {
        // The two routes that emit a plaintext token are the only two whose body
        // the BFF has to strip. The state inside it carries no credential on any
        // path, so the television's copy is already safe.
        $created = $this->write('POST', '/play-again')->assertCreated();

        $this->assertStringNotContainsString(
            (string) $created->json('data.controller_token'),
            (string) json_encode($created->json('data.game')),
        );
    }

    public function test_the_previous_games_token_does_not_write_to_the_rematch(): void
    {
        $created = $this->write('POST', '/play-again')->assertCreated();
        $nextGameId = (string) $created->json('data.game.game_id');

        $this->json('POST', "/api/v1/games/{$nextGameId}/start", [], [
            'X-Trident-Controller-Token' => $this->token,
        ])->assertStatus(403)->assertJsonPath('error', 'controller_token_invalid');
    }

    public function test_a_rematch_of_a_finished_table_is_still_a_new_game(): void
    {
        $game = $this->games->find(GameId::fromString($this->gameId));

        $this->assertNotNull($game);
        $game->abandon(new SystemClock, FinishReason::IDLE_TIMEOUT);
        $this->games->save($game);

        $this->write('POST', '/play-again')
            ->assertCreated()
            ->assertJsonPath('data.game.status', GameStatus::LOBBY);
    }
}
