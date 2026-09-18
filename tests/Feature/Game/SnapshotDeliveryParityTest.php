<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Src\Game\Application\Service\GameProjector;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\Seed;
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
     * A literal shuffle seed.
     *
     * A board dealt from chance makes this class flaky rather than false: with a
     * random seed, one run in forty-nine turns position one over into the tile
     * that ends the stage, and the pool the two paths then agree about is the
     * next stage's fresh one.
     */
    private const SEED = 'ZbVQ8vUCcVNJNCYLbhwSEhz1vmKpSiIk0WBlGzHU7Ss';

    /** The one state shape of the system, in order. */
    private const SHAPE = [
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
    ];

    /**
     * A saved game whose board is the same board on every run, and the plaintext
     * token that writes to it.
     *
     * @return array{string, string}
     */
    private function tableWithALiteralSeed(): array
    {
        $token = ControllerToken::generate();

        $game = Game::open(
            GameId::random(),
            JoinCode::fromString('K7QP3M'),
            $token,
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-17 20:00:00'),
            Seed::fromString(self::SEED),
        );

        $this->app->make(GameRepository::class)->save($game);

        return [$game->id()->value(), $token->value()];
    }

    /**
     * Turns the election over position by position until the double three ends
     * it, leaving the game on the first draw of `main`.
     */
    private function playTheElection(string $gameId, string $token): void
    {
        for ($position = 1; $position <= 49; $position++) {
            $stage = $this->postJson("/api/v1/games/{$gameId}/pool/{$position}/draw", [], [
                'X-Trident-Controller-Token' => $token,
            ])->assertOk()->json('data.stage');

            if ($stage === 'main') {
                return;
            }
        }

        self::fail('The election never reached the double three (TR-26).');
    }

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
     * The raw body of `GET /api/v1/games/{gameId}`, exactly as it goes down the
     * wire. It is the body and not the decoded array because decoding is what
     * destroys the difference between `{}` and `[]`, and the claim being made
     * here is about bytes.
     */
    private function httpBody(string $gameId): string
    {
        return (string) $this->getJson("/api/v1/games/{$gameId}")->assertOk()->getContent();
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
     */
    private function assertIdenticalBytes(array $broadcast, string $body): void
    {
        $encoded = (string) json_encode($broadcast);

        // The decoded comparison gives a readable diff; the encoded one is the
        // rule: the envelope carries the broadcast's bytes verbatim, which pins
        // key order, the JSON types, and an empty map as `{}` rather than `[]`.
        $this->assertSame(
            json_decode($encoded, true),
            json_decode($body, true)['data'] ?? null,
            'The two delivery paths carry the same state.',
        );
        $this->assertStringContainsString('"data":'.$encoded, $body);
    }

    public function test_the_http_envelope_and_the_broadcast_agree_when_a_game_is_created(): void
    {
        Event::fake([GameStateChanged::class]);

        $created = $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', 'Caro']])
            ->assertCreated();

        $gameId = (string) $created->json('data.game.game_id');

        $this->assertIdenticalBytes($this->broadcastPayload(), $this->httpBody($gameId));
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

        $this->assertIdenticalBytes($this->broadcastPayload(), $this->httpBody($gameId));
        $this->assertSame(2, $http['version']);
        $this->assertSame('Bea María', $http['seats'][1]['nickname']);
    }

    public function test_the_http_envelope_and_the_broadcast_agree_once_a_board_is_on_the_table(): void
    {
        // The case the earlier phases could not reach: a game in play carries a
        // stage, a cursor, a pool of 49 positions and the table's settings
        // resolved into every declared key. That last one is what `jsonb` reorders
        // — it stores an object's keys by length and then by bytes — so a game
        // read back out of a row and a game just broadcast agree here only
        // because the projection imposes an order of its own.
        [$gameId, $token] = $this->tableWithALiteralSeed();

        $this->postJson("/api/v1/games/{$gameId}/start", [], ['X-Trident-Controller-Token' => $token])
            ->assertOk();

        Event::fake([GameStateChanged::class]);

        $this->postJson("/api/v1/games/{$gameId}/pool/1/draw", [], ['X-Trident-Controller-Token' => $token])
            ->assertOk();

        $http = $this->httpPayload($gameId);

        $this->assertIdenticalBytes($this->broadcastPayload(), $this->httpBody($gameId));
        $this->assertCount(49, $http['pool']);
        $this->assertTrue($http['pool'][0]['taken']);
        $this->assertIsString($http['pool'][0]['tile'], 'A taken position shows its face on both paths.');
        $this->assertNull($http['pool'][1]['tile'], 'And an untaken one hides it on both paths.');
        $this->assertSame(1, $http['pool'][0]['seat'], 'And it names the seat that took it on both paths.');
        $this->assertNull($http['pool'][1]['seat']);
        $this->assertNotSame([], (array) $http['room_config'], 'The settings were resolved when play began.');
    }

    public function test_the_http_envelope_and_the_broadcast_agree_in_the_middle_of_the_second_stage(): void
    {
        // The stage whose settings clear the board as it fills, which is the one
        // combination the first stage never produces: a taken position that
        // carries a face, a taker and `on_board: false`. The row and the live
        // aggregate have to agree about all three.
        //
        // The stage is read off the response and never named here: which stages
        // exist and which tile ends one are the ruleset's, and this file is about
        // the two delivery paths.
        [$gameId, $token] = $this->tableWithALiteralSeed();

        $opening = (string) $this->postJson(
            "/api/v1/games/{$gameId}/start",
            [],
            ['X-Trident-Controller-Token' => $token],
        )->assertOk()->json('data.stage');

        $stage = $opening;

        for ($position = 1; $position <= 49 && $stage === $opening; $position++) {
            $stage = (string) $this->postJson("/api/v1/games/{$gameId}/pool/{$position}/draw", [], [
                'X-Trident-Controller-Token' => $token,
            ])->assertOk()->json('data.stage');
        }

        $this->assertNotSame($opening, $stage, 'The game never left its first stage.');

        Event::fake([GameStateChanged::class]);

        // One draw into the second stage, whose pool is a fresh one: position one
        // is untaken in it whatever the first stage did (TR-04, TR-31).
        $this->postJson("/api/v1/games/{$gameId}/pool/1/draw", [], [
            'X-Trident-Controller-Token' => $token,
        ])->assertOk();

        $http = $this->httpPayload($gameId);

        $this->assertIdenticalBytes($this->broadcastPayload(), $this->httpBody($gameId));
        $this->assertTrue($http['pool'][0]['taken']);
        $this->assertIsString($http['pool'][0]['tile']);
        $this->assertIsInt($http['pool'][0]['seat']);
        $this->assertFalse(
            $http['pool'][0]['on_board'],
            'The settings of this stage take a drawn tile off the board (TR-52).',
        );
        $this->assertTrue($http['pool'][1]['on_board'], 'An untaken position stays on it.');
    }

    public function test_both_paths_carry_the_effects_of_the_write_they_belong_to(): void
    {
        // The seam's whole output travels here or nowhere: a challenge names the
        // seat it is addressed to, so no client has to work out for itself that
        // the face of three belongs to the trident (TR-44). The television that
        // missed the frame reads the same bytes from the GET.
        [$gameId, $token] = $this->tableWithALiteralSeed();

        $this->postJson("/api/v1/games/{$gameId}/start", [], ['X-Trident-Controller-Token' => $token])
            ->assertOk();

        // The election fires nothing (TR-23), so the question this test asks has
        // no answer until the game is in `main`.
        $this->playTheElection($gameId, $token);

        Event::fake([GameStateChanged::class]);

        $drawn = $this->postJson("/api/v1/games/{$gameId}/pool/1/draw", [], [
            'X-Trident-Controller-Token' => $token,
        ])->assertOk();

        $effects = (array) $drawn->json('data.effects');

        $this->assertNotSame([], $effects, 'A turned-over tile fires something in main (TR-38).');

        foreach ($effects as $effect) {
            $this->assertArrayHasKey('kind', (array) $effect);
        }

        $this->assertSame($effects, (array) $this->broadcastPayload()['effects']);
        $this->assertSame($effects, (array) $this->httpPayload($gameId)['effects']);
    }

    public function test_a_write_that_consulted_no_rule_carries_no_effects(): void
    {
        // They belong to the write this version came from and to no other: a
        // rename must not re-announce the challenge of the draw before it.
        [$gameId, $token] = $this->tableWithALiteralSeed();

        $this->postJson("/api/v1/games/{$gameId}/start", [], ['X-Trident-Controller-Token' => $token])
            ->assertOk();
        $this->postJson("/api/v1/games/{$gameId}/pool/1/draw", [], ['X-Trident-Controller-Token' => $token])
            ->assertOk();

        Event::fake([GameStateChanged::class]);

        $renamed = $this->patchJson("/api/v1/games/{$gameId}/seats/2", ['nickname' => 'Bea María'], [
            'X-Trident-Controller-Token' => $token,
        ])->assertOk();

        $this->assertSame([], (array) $renamed->json('data.effects'));
        $this->assertSame([], (array) $this->httpPayload($gameId)['effects']);
        $this->assertIdenticalBytes($this->broadcastPayload(), $this->httpBody($gameId));
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
        $game->abandon(FrozenClock::at('2026-09-16 21:00:00'), FinishReason::IDLE_TIMEOUT);
        $games->save($game);

        // What the publisher is handed at the moment of the write: the live
        // aggregate, not a reload.
        $broadcast = new GameStateChanged(
            $this->app->make(GameProjector::class)->project($game),
        )->broadcastWith();
        $http = $this->httpPayload($game->id()->value());

        $this->assertIdenticalBytes($broadcast, $this->httpBody($game->id()->value()));
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
            $this->assertSame(self::SHAPE, array_keys($payload));

            $scanned = strtolower((string) json_encode(array_diff_key($payload, ['join_code' => null])));

            foreach (['token', 'secret', 'hash'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $scanned);
            }
        }
    }

    public function test_neither_path_carries_the_shuffle_seed_once_a_board_exists(): void
    {
        // Whoever holds the seed recomputes every face-down position of every
        // pool and ends the game in silence, with nothing in any log to show for
        // it. The claim is asserted by value, against the row's own column.
        [$gameId, $token] = $this->tableWithALiteralSeed();

        $this->postJson("/api/v1/games/{$gameId}/start", [], ['X-Trident-Controller-Token' => $token])
            ->assertOk();

        $seed = (string) DB::table('games')->where('id', $gameId)->value('shuffle_seed');

        $this->assertNotSame('', $seed, 'A game in play has a seed to leak.');

        Event::fake([GameStateChanged::class]);

        $this->postJson("/api/v1/games/{$gameId}/pool/1/draw", [], ['X-Trident-Controller-Token' => $token])
            ->assertOk();

        $this->assertStringNotContainsString($seed, (string) json_encode($this->broadcastPayload()));
        $this->assertStringNotContainsString($seed, $this->httpBody($gameId));
    }

    public function test_the_envelope_keeps_its_shape_around_the_projection(): void
    {
        // `data` is the snapshot and nothing else: no wrapper key, no extra field
        // that only one of the two clients would learn about.
        $created = $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', 'Caro']])
            ->assertCreated();

        $response = $this->getJson('/api/v1/games/'.$created->json('data.game.game_id'))->assertOk();

        $this->assertSame(['status', 'message', 'error', 'data'], array_keys((array) $response->json()));
        $this->assertSame(self::SHAPE, array_keys((array) $response->json('data')));
    }
}
