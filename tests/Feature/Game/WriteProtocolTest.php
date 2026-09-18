<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Realtime\Infrastructure\Broadcasting\GameStateChanged;
use Src\Shared\Domain\ValueObjects\Uuid;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\TestCase;

/**
 * The two guards every write carries, against the real database.
 *
 * They belong here and not beside a handler: the idempotency ledger **is** the
 * unique index on `game_moves.request_id`, so a double must not be what decides
 * whether the ledger works. It runs on both engines for the same reason.
 *
 * The scenario is a drinking table on bad wifi: the phone taps, sees nothing,
 * taps again. The turn has to advance once and the second answer has to be the
 * first answer, byte for byte.
 */
final class WriteProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const GAME_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    /** A literal shuffle seed: a write protocol tested on chance proves nothing. */
    private const SEED = 'ZbVQ8vUCcVNJNCYLbhwSEhz1vmKpSiIk0WBlGzHU7Ss';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $token = ControllerToken::generate();

        $game = Game::open(
            GameId::fromString(self::GAME_ID),
            JoinCode::fromString('K7QP3M'),
            $token,
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-17 20:00:00'),
            Seed::fromString(self::SEED),
        );

        $this->app->make(GameRepository::class)->save($game);

        $this->token = $token->value();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function write(string $method, string $path, array $body = [], ?string $requestId = null): TestResponse
    {
        $headers = ['X-Trident-Controller-Token' => $this->token];

        if ($requestId !== null) {
            $headers['X-Request-Id'] = $requestId;
        }

        return $this->json($method, '/api/v1/games/'.self::GAME_ID.$path, $body, $headers);
    }

    private function started(): void
    {
        $this->write('POST', '/start')->assertOk();
    }

    private function versionOf(string $gameId = self::GAME_ID): int
    {
        return (int) DB::table('games')->where('id', $gameId)->value('version');
    }

    private function drawCount(string $gameId = self::GAME_ID): int
    {
        return DB::table('game_moves')
            ->where('game_id', $gameId)
            ->where('kind', MoveKind::TILE_DRAWN)
            ->count();
    }

    // ---------------------------------------------------------- idempotency

    public function test_a_repeated_write_intention_advances_the_turn_once(): void
    {
        $this->started();
        $id = Uuid::random()->value();

        $first = $this->write('POST', '/pool/1/draw', [], $id)->assertOk();
        $second = $this->write('POST', '/pool/1/draw', [], $id)->assertOk();

        $this->assertSame(
            $first->getContent(),
            $second->getContent(),
            'The second answer is the first answer, byte for byte.',
        );
        $this->assertSame(1, $this->drawCount(), 'One tap, one entry in the log.');
        $this->assertSame(3, $this->versionOf());
    }

    public function test_a_replay_is_answered_and_not_refused_as_a_stale_write(): void
    {
        // The order of the two guards. A retry carries the `expected_version` of
        // the attempt that already succeeded, so a version check placed first
        // would answer every successful retry with a conflict.
        $this->started();
        $id = Uuid::random()->value();

        $first = $this->write('POST', '/pool/1/draw', ['expected_version' => 2], $id)->assertOk();
        $second = $this->write('POST', '/pool/1/draw', ['expected_version' => 2], $id)->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, $this->drawCount());
    }

    public function test_a_replay_broadcasts_nothing_a_second_time(): void
    {
        $this->started();
        Event::fake([GameStateChanged::class]);

        $id = Uuid::random()->value();
        $this->write('POST', '/pool/1/draw', [], $id)->assertOk();
        $this->write('POST', '/pool/1/draw', [], $id)->assertOk();

        Event::assertDispatchedTimes(GameStateChanged::class, 1);
    }

    public function test_the_write_intention_is_stored_on_the_entry_it_belongs_to(): void
    {
        $this->started();
        $id = Uuid::random()->value();

        $this->write('POST', '/pool/1/draw', [], $id)->assertOk();

        $row = DB::table('game_moves')->where('kind', MoveKind::TILE_DRAWN)->first();

        $this->assertNotNull($row);
        $this->assertSame($id, (string) $row->request_id);
    }

    public function test_a_write_that_carries_no_intention_records_none(): void
    {
        // The column is nullable and a unique treats NULLs as distinct, so the
        // ledger holds only the writes that asked to be in it.
        $this->started();

        $this->write('POST', '/pool/1/draw')->assertOk();
        $this->write('POST', '/pool/2/draw')->assertOk();

        $this->assertSame(0, DB::table('game_moves')->whereNotNull('request_id')->count());
        $this->assertSame(2, $this->drawCount());
    }

    public function test_a_write_that_changed_nothing_records_no_intention(): void
    {
        // Nothing changed, so there is no entry to carry the id and nothing for a
        // repeat to be answered with: the identity permutation is the one write
        // that is idempotent all by itself.
        $id = Uuid::random()->value();

        $this->write('PUT', '/seats/order', ['order' => [1, 2, 3]], $id)
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $this->assertSame(0, DB::table('game_moves')->where('request_id', $id)->count());
    }

    public function test_an_intention_already_spent_on_another_game_is_refused(): void
    {
        // The ledger is global and one id can only ever mean one intention:
        // applying it to a game it never addressed is the defect idempotency
        // exists to prevent, so it is refused rather than replayed.
        $this->started();
        $id = Uuid::random()->value();
        $this->write('POST', '/pool/1/draw', [], $id)->assertOk();

        $other = $this->postJson('/api/v1/games', ['nicknames' => ['Dani', 'Eva', 'Fran']])->assertCreated();
        $otherId = (string) $other->json('data.game.game_id');

        $this->json('POST', "/api/v1/games/{$otherId}/start", [], [
            'X-Trident-Controller-Token' => (string) $other->json('data.controller_token'),
            'X-Request-Id' => $id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'request_id_reused');

        $this->assertSame(1, $this->versionOf($otherId), 'The refused write left that game alone.');
    }

    public function test_a_rematch_keeps_the_previous_ledger_so_a_spent_intention_cannot_apply_again(): void
    {
        // A rematch opens a new game and empties nothing. If it had emptied the
        // previous game's entries, this id would be free again and the tap that
        // was already counted would count twice.
        $this->started();
        $id = Uuid::random()->value();
        $this->write('POST', '/pool/1/draw', [], $id)->assertOk();

        $created = $this->write('POST', '/play-again')->assertCreated();
        $nextGameId = (string) $created->json('data.game.game_id');

        $this->json('POST', "/api/v1/games/{$nextGameId}/start", [], [
            'X-Trident-Controller-Token' => (string) $created->json('data.controller_token'),
            'X-Request-Id' => $id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'request_id_reused');

        $this->assertSame(1, $this->drawCount(), 'The previous game keeps its one entry.');
        $this->assertSame(4, $this->versionOf(), 'It gains one version — its ending — and loses nothing.');
    }

    public function test_a_malformed_write_intention_is_refused_before_anything_is_written(): void
    {
        // The column is `uuid`: without this the write would be applied in memory
        // and then die as a 500 in the driver.
        $this->started();

        $this->write('POST', '/pool/1/draw', [], 'not-a-uuid')
            ->assertStatus(422)
            ->assertJsonPath('error', 'request_id_invalid');

        $this->assertSame(0, $this->drawCount());
        $this->assertSame(2, $this->versionOf());
    }

    // -------------------------------------------------------- version guard

    public function test_a_write_that_names_the_version_it_read_is_applied(): void
    {
        $this->started();

        $this->write('POST', '/pool/1/draw', ['expected_version' => 2])
            ->assertOk()
            ->assertJsonPath('data.version', 3);
    }

    public function test_a_stale_write_is_refused_with_the_current_state(): void
    {
        // The phone heals from the refusal itself instead of asking for the state
        // in a second request.
        $this->started();
        $this->write('POST', '/pool/1/draw')->assertOk();

        $refused = $this->write('POST', '/pool/2/draw', ['expected_version' => 2])
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_version_conflict')
            ->assertJsonPath('data.version', 3);

        $fresh = (array) $this->getJson('/api/v1/games/'.self::GAME_ID)->assertOk()->json('data');

        $this->assertSame($fresh, (array) $refused->json('data'), 'The refusal carries the one projection.');
    }

    public function test_a_stale_write_never_reaches_the_aggregate(): void
    {
        $this->started();

        $this->write('POST', '/pool/1/draw', ['expected_version' => 99])->assertStatus(422);

        $this->assertSame(0, $this->drawCount());
        $this->assertSame(2, $this->versionOf());
    }

    public function test_the_refusal_carries_no_credential_and_no_seed(): void
    {
        $this->started();

        $body = (string) $this->write('POST', '/pool/1/draw', ['expected_version' => 99])
            ->assertStatus(422)
            ->getContent();

        $this->assertStringNotContainsString($this->token, $body);
        $this->assertStringNotContainsString(self::SEED, $body);
        $this->assertStringNotContainsString(hash('sha256', $this->token), $body);
    }

    public function test_a_version_that_is_not_a_version_is_a_validation_error(): void
    {
        // Never coerced: a guard that silently becomes "no guard" is worse than
        // no guard at all.
        $this->started();

        foreach ([0, -1, 'soon'] as $nonsense) {
            $this->write('POST', '/pool/1/draw', ['expected_version' => $nonsense])
                ->assertStatus(422)
                ->assertJsonPath('error', 'validation_error');
        }

        $this->assertSame(0, $this->drawCount());
    }

    public function test_the_lobby_writes_carry_the_same_two_guards(): void
    {
        // One write protocol, not one per route: the guards live in the writer
        // every handler goes through, so a route cannot forget them.
        $id = Uuid::random()->value();

        $first = $this->write('PUT', '/seats/order', ['order' => [3, 1, 2], 'expected_version' => 1], $id)
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $second = $this->write('PUT', '/seats/order', ['order' => [3, 1, 2], 'expected_version' => 1], $id)
            ->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());

        // Without the ledger, the same absolute permutation applied twice would
        // have renumbered the table again.
        $seats = (array) $this->getJson('/api/v1/games/'.self::GAME_ID)->assertOk()->json('data.seats');

        $this->assertSame(['Caro', 'Ana', 'Bea'], array_column($seats, 'nickname'));
    }

    public function test_the_room_configuration_carries_the_version_guard(): void
    {
        // The table sets a text, taking the game to version 2. A phone that is
        // still holding version 1 must not overwrite it in silence: the two
        // guards live in the writer, but only a test proves this route reached
        // it with what the client sent.
        $key = TridentRuleSet::challengeKey(0);

        $this->write('POST', '/room-config', ['room_config' => [$key => 'A'], 'expected_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->write('POST', '/room-config', ['room_config' => [$key => 'B'], 'expected_version' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_version_conflict')
            ->assertJsonPath('data.version', 2);

        $config = (array) $this->getJson('/api/v1/games/'.self::GAME_ID)->assertOk()->json('data.room_config');

        $this->assertSame('A', $config[$key] ?? null, 'The stale write never landed.');
    }

    public function test_the_room_configuration_carries_the_idempotency_guard(): void
    {
        $key = TridentRuleSet::challengeKey(0);
        $id = Uuid::random()->value();

        $first = $this->write('POST', '/room-config', ['room_config' => [$key => 'A']], $id)->assertOk();
        $second = $this->write('POST', '/room-config', ['room_config' => [$key => 'B']], $id)->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());

        $config = (array) $this->getJson('/api/v1/games/'.self::GAME_ID)->assertOk()->json('data.room_config');

        $this->assertSame('A', $config[$key] ?? null, 'The second settings map was never applied.');
        $this->assertSame(2, $this->versionOf());
    }

    public function test_a_rename_carries_the_version_guard(): void
    {
        // Two phones reading the same lobby: one renames seat 2, and the other
        // submits its own rename of seat 2 on the version it read before that.
        // The stale one is refused with the current state rather than silently
        // overwriting the newer name.
        $this->write('PATCH', '/seats/2', ['nickname' => 'Beatriz', 'expected_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->write('PATCH', '/seats/2', ['nickname' => 'Bea Maria', 'expected_version' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_version_conflict');

        $seats = (array) $this->getJson('/api/v1/games/'.self::GAME_ID)->assertOk()->json('data.seats');

        $this->assertSame(['Ana', 'Beatriz', 'Caro'], array_column($seats, 'nickname'));
    }

    public function test_a_rename_carries_the_idempotency_guard(): void
    {
        $id = Uuid::random()->value();

        $first = $this->write('PATCH', '/seats/2', ['nickname' => 'Beatriz'], $id)->assertOk();
        $second = $this->write('PATCH', '/seats/2', ['nickname' => 'Bea Maria'], $id)->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());

        $seats = (array) $this->getJson('/api/v1/games/'.self::GAME_ID)->assertOk()->json('data.seats');

        $this->assertSame(['Ana', 'Beatriz', 'Caro'], array_column($seats, 'nickname'));
        $this->assertSame(2, $this->versionOf());
    }

    public function test_a_rename_to_the_name_a_seat_already_holds_is_not_a_write(): void
    {
        $this->write('PATCH', '/seats/1', ['nickname' => 'Ana'])
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $this->assertSame(1, $this->versionOf());
        $this->assertSame(
            1,
            DB::table('game_moves')->where('game_id', self::GAME_ID)->count(),
            'Only the entry that opened the game.',
        );
    }

    public function test_an_intention_spent_on_one_write_cannot_stand_for_another(): void
    {
        // A client that keys the header per screen rather than per gesture, or an
        // intermediary that replays a header on a retry. Without the kind, this
        // answers `200 Tile drawn.` with the projection of the start: a tap that
        // reads as a dead tile at the table, with no error anywhere.
        $id = Uuid::random()->value();

        $this->write('POST', '/start', [], $id)->assertOk();

        $this->write('POST', '/pool/1/draw', [], $id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'request_id_reused');

        $this->assertSame(0, $this->drawCount(), 'And the tile stayed face down.');
        $this->assertSame(2, $this->versionOf());
    }

    public function test_the_same_intention_on_the_same_gesture_is_still_a_replay(): void
    {
        // The other side of the same guard: binding the ledger to the kind must
        // not turn the double tap it exists for into a refusal.
        $this->started();
        $id = Uuid::random()->value();

        $first = $this->write('POST', '/pool/1/draw', [], $id)->assertOk();
        $second = $this->write('POST', '/pool/2/draw', [], $id)->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, $this->drawCount());
    }

    public function test_a_repeated_permutation_with_no_intention_is_refused_by_the_version_guard(): void
    {
        // The other half of the same protection, for a client that sends the
        // version but no id.
        $this->write('PUT', '/seats/order', ['order' => [3, 1, 2], 'expected_version' => 1])->assertOk();

        $this->write('PUT', '/seats/order', ['order' => [3, 1, 2], 'expected_version' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_version_conflict');

        $seats = (array) $this->getJson('/api/v1/games/'.self::GAME_ID)->assertOk()->json('data.seats');

        $this->assertSame(['Caro', 'Ana', 'Bea'], array_column($seats, 'nickname'));
    }
}
