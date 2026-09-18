<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Infrastructure\Http\Middleware\VerifyControllerToken;
use Tests\Doubles\InMemoryGameRepository;
use Tests\TestCase;

final class GameEndpointsTest extends TestCase
{
    private InMemoryGameRepository $games;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games = new InMemoryGameRepository;
        $this->app->instance(GameRepository::class, $this->games);
    }

    /**
     * @param  list<string>  $nicknames
     */
    private function createGame(array $nicknames = ['Ana', 'Bea', 'Caro']): TestResponse
    {
        return $this->postJson('/api/v1/games', ['nicknames' => $nicknames]);
    }

    public function test_creating_a_game_returns_the_envelope_with_a_snapshot(): void
    {
        $this->createGame()
            ->assertCreated()
            ->assertJsonPath('status', 201)
            ->assertJsonPath('error', null)
            ->assertJsonPath('data.game.status', 'lobby')
            ->assertJsonPath('data.game.version', 1)
            ->assertJsonCount(3, 'data.game.seats');
    }

    public function test_creating_a_game_hands_back_the_controller_token_once(): void
    {
        // The only time the plaintext token crosses the wire. The BFF stores it in an
        // httpOnly cookie and strips it before it reaches the browser.
        $token = $this->createGame()->assertCreated()->json('data.controller_token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
    }

    public function test_the_snapshot_itself_never_carries_the_token(): void
    {
        $response = $this->createGame()->assertCreated();

        $this->assertStringNotContainsString(
            (string) $response->json('data.controller_token'),
            json_encode($response->json('data.game')) ?: '',
        );
    }

    public function test_a_table_that_is_too_small_is_a_business_error(): void
    {
        $this->createGame(['Ana', 'Bea'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'roster_size_invalid');
    }

    public function test_a_table_that_is_too_big_is_a_business_error(): void
    {
        $this->createGame(array_map(static fn (int $i): string => "Player{$i}", range(1, 16)))
            ->assertStatus(422)
            ->assertJsonPath('error', 'roster_size_invalid');
    }

    public function test_repeated_names_are_rejected(): void
    {
        $this->createGame(['Ana', 'Bea', 'ana'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'nickname_taken');
    }

    public function test_missing_nicknames_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/games', [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_a_nickname_that_is_not_a_string_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', ['I am not a name']]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_a_game_can_be_read_back_by_id(): void
    {
        $gameId = $this->createGame()->json('data.game.game_id');

        $this->getJson("/api/v1/games/{$gameId}")
            ->assertOk()
            ->assertJsonPath('data.game_id', $gameId)
            ->assertJsonPath('data.version', 1);
    }

    public function test_an_unknown_game_is_a_404_with_a_machine_code(): void
    {
        $this->getJson('/api/v1/games/0f8fad5b-d9cb-469f-a165-70867728950e')
            ->assertStatus(404)
            ->assertJsonPath('error', 'game_not_found');
    }

    public function test_a_television_can_find_the_game_by_typing_the_code(): void
    {
        $created = $this->createGame()->assertCreated();
        $code = $created->json('data.game.join_code');

        $this->getJson("/api/v1/games/by-code/{$code}")
            ->assertOk()
            ->assertJsonPath('data.game_id', $created->json('data.game.game_id'));
    }

    public function test_the_by_code_route_is_not_swallowed_by_the_id_route(): void
    {
        // `by-code` is declared BEFORE `{gameId}`, or the dynamic parameter captures
        // it and the television never finds the game.
        $this->getJson('/api/v1/games/by-code/ZZZZZZ')
            ->assertStatus(404)
            ->assertJsonPath('error', 'game_not_found');
    }

    public function test_the_typed_code_forgives_lowercase_and_spacing(): void
    {
        $created = $this->createGame()->assertCreated();
        $code = (string) $created->json('data.game.join_code');

        $this->getJson('/api/v1/games/by-code/'.strtolower($code))
            ->assertOk()
            ->assertJsonPath('data.game_id', $created->json('data.game.game_id'));
    }

    public function test_renaming_a_seat_requires_the_controller_token(): void
    {
        $gameId = $this->createGame()->json('data.game.game_id');

        $this->patchJson("/api/v1/games/{$gameId}/seats/2", ['nickname' => 'Bea María'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'controller_token_required');
    }

    public function test_a_wrong_controller_token_is_refused(): void
    {
        $gameId = $this->createGame()->json('data.game.game_id');

        $this->patchJson("/api/v1/games/{$gameId}/seats/2", ['nickname' => 'Bea María'], [
            'X-Trident-Controller-Token' => str_repeat('a', 43),
        ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'controller_token_invalid');
    }

    public function test_the_phone_holding_the_token_can_rename_a_seat(): void
    {
        $created = $this->createGame()->assertCreated();

        $this->patchJson(
            "/api/v1/games/{$created->json('data.game.game_id')}/seats/2",
            ['nickname' => 'Bea María'],
            ['X-Trident-Controller-Token' => $created->json('data.controller_token')],
        )
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.seats.1.nickname', 'Bea María');
    }

    public function test_renaming_to_a_name_someone_else_has_is_refused(): void
    {
        $created = $this->createGame()->assertCreated();

        $this->patchJson(
            "/api/v1/games/{$created->json('data.game.game_id')}/seats/2",
            ['nickname' => 'Ana'],
            ['X-Trident-Controller-Token' => $created->json('data.controller_token')],
        )
            ->assertStatus(422)
            ->assertJsonPath('error', 'nickname_taken');
    }

    public function test_renaming_an_empty_seat_is_refused(): void
    {
        $created = $this->createGame()->assertCreated();

        $this->patchJson(
            "/api/v1/games/{$created->json('data.game.game_id')}/seats/9",
            ['nickname' => 'Zoe'],
            ['X-Trident-Controller-Token' => $created->json('data.controller_token')],
        )
            ->assertStatus(422)
            ->assertJsonPath('error', 'seat_not_found');
    }

    public function test_a_token_for_another_game_cannot_rename_this_one(): void
    {
        $mine = $this->createGame(['Ana', 'Bea', 'Caro'])->assertCreated();
        $other = $this->createGame(['Dani', 'Eva', 'Fran'])->assertCreated();

        $this->patchJson(
            "/api/v1/games/{$mine->json('data.game.game_id')}/seats/2",
            ['nickname' => 'Bea María'],
            ['X-Trident-Controller-Token' => $other->json('data.controller_token')],
        )->assertStatus(403);
    }

    /**
     * Every non-GET route of `/api/v1/games/*`, as `METHOD uri`, sorted.
     *
     * @return list<string>
     */
    private function mutatingRoutes(): array
    {
        $mutations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

            if (! str_starts_with($uri, 'api/v1/games') || $methods === ['GET']) {
                continue;
            }

            $mutations[] = implode('|', $methods).' '.$uri;
        }

        sort($mutations);

        return $mutations;
    }

    public function test_the_sweeper_sees_every_mutating_game_route(): void
    {
        // The three tests below iterate `Route::getRoutes()`, so a route that was
        // never registered passes them by looking at nothing. This is the list
        // itself: a new mutating route lands here in the same commit, and a route
        // that disappears cannot take its guard's coverage with it in silence.
        $this->assertSame([
            'PATCH api/v1/games/{gameId}/seats/{seat}',
            'POST api/v1/games',
            'POST api/v1/games/{gameId}/play-again',
            'POST api/v1/games/{gameId}/pool/{position}/draw',
            'POST api/v1/games/{gameId}/room-config',
            'POST api/v1/games/{gameId}/start',
            'PUT api/v1/games/{gameId}/seats/order',
        ], $this->mutatingRoutes());
    }

    public function test_every_mutating_game_route_demands_the_controller_token(): void
    {
        // Sweeper test: a new mutating route and its row here land in the same
        // commit. It replaces the guidelines' AllListingsTenantRequiredTest, now
        // that multi-tenancy is waived.
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $methods = array_diff($route->methods(), ['HEAD', 'OPTIONS']);

            if (! str_starts_with($uri, 'api/v1/games') || $methods === ['GET']) {
                continue;
            }

            // Creating a game is the only mutation that cannot demand the token:
            // it is precisely the one that issues it. `play-again` is NOT an
            // exception, because it demands the token of the game in play in
            // order to issue the next game's.
            if ($uri === 'api/v1/games' && in_array('POST', $methods, true)) {
                continue;
            }

            $guards = $route->gatherMiddleware();

            if (! in_array(VerifyControllerToken::class, $guards, true)) {
                $unguarded[] = implode('|', $methods).' '.$uri;
            }
        }

        $this->assertSame([], $unguarded, 'Every game mutation must require the controller token.');
    }

    /**
     * One real request per mutating route, with the path parameters filled in.
     *
     * The middleware runs before the controller is resolved, so no route needs a
     * valid body to be refused: the guard is asserted by its code and not by the
     * shape of what it was handed.
     *
     * @return list<array{string, string}>
     */
    private function mutationsAgainst(string $gameId): array
    {
        $requests = [];

        foreach ($this->mutatingRoutes() as $mutation) {
            [$methods, $uri] = explode(' ', $mutation, 2);

            if ($uri === 'api/v1/games') {
                continue;
            }

            $requests[] = [
                explode('|', $methods)[0],
                '/'.str_replace(['{gameId}', '{seat}', '{position}'], [$gameId, '2', '1'], $uri),
            ];
        }

        return $requests;
    }

    public function test_no_mutating_game_route_answers_without_a_token(): void
    {
        $gameId = (string) $this->createGame()->json('data.game.game_id');
        $seen = 0;

        foreach ($this->mutationsAgainst($gameId) as [$method, $path]) {
            $this->json($method, $path)
                ->assertStatus(401)
                ->assertJsonPath('error', 'controller_token_required');

            $seen++;
        }

        $this->assertGreaterThan(0, $seen, 'No mutation was swept: this test is not testing anything.');
    }

    public function test_no_mutating_game_route_answers_to_the_wrong_token(): void
    {
        $gameId = (string) $this->createGame()->json('data.game.game_id');
        $seen = 0;

        foreach ($this->mutationsAgainst($gameId) as [$method, $path]) {
            $this->json($method, $path, [], ['X-Trident-Controller-Token' => str_repeat('a', 43)])
                ->assertStatus(403)
                ->assertJsonPath('error', 'controller_token_invalid');

            $seen++;
        }

        $this->assertGreaterThan(0, $seen, 'No mutation was swept: this test is not testing anything.');
    }
}
