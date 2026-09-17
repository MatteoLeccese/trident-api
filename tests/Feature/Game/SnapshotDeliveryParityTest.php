<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Realtime\Infrastructure\Broadcasting\GameStateChanged;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\TestCase;

/**
 * Golden rule 2: there is ONE state projection.
 *
 * What `GET /api/v1/games/{gameId}` puts in the envelope's `data` and what
 * `GameStateChanged::broadcastWith()` sends to the televisions are the same
 * bytes. The comparison is made over a REAL HTTP request against the REAL
 * repository, so the controller, the envelope and the reload from storage are all
 * inside the assertion: a snapshot compared against itself would prove nothing
 * about the two paths that actually deliver it.
 *
 * The in-memory double is deliberately not bound: the phone reads the game one
 * request after the broadcast went out, which means it reads it out of the
 * database.
 */
final class SnapshotDeliveryParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The payload of the single broadcast the write emitted.
     *
     * @return array<string, mixed>
     */
    private function broadcastPayload(): array
    {
        $payloads = [];

        Event::assertDispatched(
            GameStateChanged::class,
            static function (GameStateChanged $event) use (&$payloads): bool {
                $payloads[] = $event->broadcastWith();

                return true;
            },
        );

        $this->assertCount(1, $payloads, 'A write emits exactly one state broadcast.');

        return $payloads[0];
    }

    /**
     * The envelope's `data` for that game, read back over HTTP.
     *
     * @return array<string, mixed>
     */
    private function httpPayload(string $gameId): array
    {
        $data = $this->getJson("/api/v1/games/{$gameId}")->assertOk()->json('data');

        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $broadcast
     * @param  array<string, mixed>  $http
     */
    private function assertIdenticalBytes(array $broadcast, array $http): void
    {
        // The array comparison gives a readable diff; the encoded one is the rule:
        // identical bytes, which also pins key order and the JSON types.
        $this->assertSame($broadcast, $http, 'The two delivery paths carry the same state.');
        $this->assertSame(json_encode($broadcast), json_encode($http));
    }

    public function test_the_http_envelope_and_the_broadcast_agree_when_a_game_is_created(): void
    {
        Event::fake([GameStateChanged::class]);

        $created = $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', 'Caro']])
            ->assertCreated();

        $gameId = (string) $created->json('data.game.game_id');

        $this->assertIdenticalBytes($this->broadcastPayload(), $this->httpPayload($gameId));
    }

    public function test_the_http_envelope_and_the_broadcast_agree_after_a_rename(): void
    {
        $created = $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', 'Caro']])
            ->assertCreated();

        $gameId = (string) $created->json('data.game.game_id');

        Event::fake([GameStateChanged::class]);

        $this->patchJson(
            "/api/v1/games/{$gameId}/seats/2",
            ['nickname' => 'Bea María'],
            ['X-Trident-Controller-Token' => (string) $created->json('data.controller_token')],
        )->assertOk();

        $http = $this->httpPayload($gameId);

        $this->assertIdenticalBytes($this->broadcastPayload(), $http);
        $this->assertSame(2, $http['version']);
        $this->assertSame('Bea María', $http['seats'][1]['nickname']);
    }

    public function test_the_http_envelope_and_the_broadcast_agree_for_a_finished_game(): void
    {
        // The hard case. A terminal game releases its code, so the row no longer
        // holds it while the aggregate that was just broadcast still remembers it.
        // Both paths have to project the same thing for a game nobody can join.
        $games = $this->app->make(GameRepository::class);

        $game = Game::open(
            GameId::fromString('0f8fad5b-d9cb-469f-a165-70867728950e'),
            JoinCode::fromString('K7QP3M'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-16 20:00:00'),
        );

        $games->save($game);
        $game->abandon(FrozenClock::at('2026-09-16 21:00:00'));
        $games->save($game);

        // What the publisher is handed at the moment of the write: the live
        // aggregate, not a reload.
        $broadcast = new GameStateChanged($game->snapshot())->broadcastWith();
        $http = $this->httpPayload($game->id()->value());

        $this->assertIdenticalBytes($broadcast, $http);
        $this->assertSame('abandoned', $http['status']);
        $this->assertNull($http['join_code'], 'A released code is not projected to anybody.');
    }

    public function test_the_projection_carries_no_credential_on_either_path(): void
    {
        Event::fake([GameStateChanged::class]);

        $created = $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', 'Caro']])
            ->assertCreated();

        $gameId = (string) $created->json('data.game.game_id');
        $token = (string) $created->json('data.controller_token');

        foreach ([$this->broadcastPayload(), $this->httpPayload($gameId)] as $payload) {
            $this->assertStringNotContainsString($token, (string) json_encode($payload));

            // The scan runs over the keys and over everything except the join code:
            // the code is drawn from an alphabet that holds H, A and S, so scanning
            // the whole blob for 'hash' turns a correct projection red now and then.
            $this->assertSame(
                ['game_id', 'version', 'status', 'join_code', 'seats', 'last_activity_at'],
                array_keys($payload),
            );

            $scanned = strtolower((string) json_encode(array_diff_key($payload, ['join_code' => null])));

            foreach (['token', 'secret', 'hash'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $scanned);
            }
        }
    }

    public function test_the_envelope_keeps_its_shape_around_the_projection(): void
    {
        // `data` is the snapshot and nothing else: no wrapper key, no extra field
        // that only one of the two clients would learn about.
        $created = $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', 'Caro']])
            ->assertCreated();

        $response = $this->getJson('/api/v1/games/'.$created->json('data.game.game_id'))->assertOk();

        $this->assertSame(['status', 'message', 'error', 'data'], array_keys((array) $response->json()));
        $this->assertSame(
            ['game_id', 'version', 'status', 'join_code', 'seats', 'last_activity_at'],
            array_keys((array) $response->json('data')),
        );
    }
}
