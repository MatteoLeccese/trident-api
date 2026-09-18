<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\RoomConfigField;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Infrastructure\Http\Middleware\VerifyControllerToken;
use Tests\Doubles\InMemoryGameRepository;
use Tests\TestCase;

/**
 * The lobby form's only delivery path.
 *
 * The form is **generated** from this declaration and never hand-wired: a screen
 * that listed the seven challenge keys of TR-43 would be a client naming a rule,
 * which is the one thing the seam exists to prevent
 * (documentation/conventions/rule-set-seam.md).
 *
 * The tests below hold the three properties the route was chosen for: it answers
 * a lobby that is pinned to no ruleset, it is the same declaration the
 * `room-config` write validates against, and being a GET it is outside the
 * mutation sweeper rather than an exception registered in it.
 */
final class RoomConfigSpecEndpointTest extends TestCase
{
    private InMemoryGameRepository $games;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games = new InMemoryGameRepository;
        $this->app->instance(GameRepository::class, $this->games);
    }

    /**
     * @return array{id: string, token: string}
     */
    private function createGame(): array
    {
        $created = $this->postJson('/api/v1/games', ['nicknames' => ['Ana', 'Bea', 'Caro']])
            ->assertCreated();

        return [
            'id' => (string) $created->json('data.game.game_id'),
            'token' => (string) $created->json('data.controller_token'),
        ];
    }

    private function spec(string $gameId): TestResponse
    {
        return $this->getJson("/api/v1/games/{$gameId}/room-config-spec");
    }

    public function test_it_answers_with_the_envelope_the_rest_of_the_api_uses(): void
    {
        $game = $this->createGame();

        $response = $this->spec($game['id'])->assertOk();

        $this->assertSame(
            ['status', 'message', 'error', 'data'],
            array_keys((array) $response->json()),
        );

        $response->assertJsonPath('status', 200)->assertJsonPath('error', null);
    }

    public function test_a_lobby_pinned_to_no_ruleset_still_gets_its_declaration(): void
    {
        // The whole reason this is a route and not a snapshot field: a game holds
        // no `rule_set_id` until it starts, and this read resolves the ruleset a
        // write on it would be decided by.
        $game = $this->createGame();

        $this->assertNull($this->games->find(GameId::fromString($game['id']))?->ruleSetId());

        $this->spec($game['id'])
            ->assertOk()
            ->assertJsonPath('data.rule_set_id', TridentRuleSet::ID);
    }

    public function test_it_declares_the_seven_challenge_keys_and_the_two_presentation_keys(): void
    {
        // TR-43: exactly seven challenge keys, one per face, none indexed by tile.
        // TR-52: one presentation key per stage.
        $game = $this->createGame();

        $keys = array_column((array) $this->spec($game['id'])->assertOk()->json('data.fields'), 'key');

        $this->assertSame(
            (new TridentRuleSet)->roomConfigSpec()->keys(),
            $keys,
            'The wire order is the declaration order the lobby paints in.',
        );
        $this->assertCount(9, $keys);
    }

    public function test_every_field_carries_every_key_of_the_declaration(): void
    {
        // A key that is sometimes there is a contract nobody can type, so each
        // field carries all six with an explicit null where one does not apply.
        $game = $this->createGame();

        /** @var list<array<string, mixed>> $fields */
        $fields = (array) $this->spec($game['id'])->assertOk()->json('data.fields');

        foreach ($fields as $field) {
            $this->assertSame(
                ['key', 'kind', 'label', 'default', 'max_length', 'options'],
                array_keys($field),
            );
            $this->assertContains($field['kind'], RoomConfigField::KINDS);
            $this->assertNotSame('', $field['label']);
            // A declared default is never empty (TR-51).
            $this->assertNotSame('', $field['default']);
        }
    }

    public function test_the_declaration_is_what_the_room_config_write_validates_against(): void
    {
        // The form and the validator are one declaration. Every key the route
        // publishes is accepted by the write, with the default the route
        // published, and a key the route does not publish is refused.
        $game = $this->createGame();

        /** @var list<array<string, mixed>> $fields */
        $fields = (array) $this->spec($game['id'])->assertOk()->json('data.fields');

        $submitted = [];

        foreach ($fields as $field) {
            $submitted[(string) $field['key']] = $field['default'];
        }

        $this->json('POST', "/api/v1/games/{$game['id']}/room-config", ['room_config' => $submitted], [
            'X-Trident-Controller-Token' => $game['token'],
        ])->assertOk();

        $this->json('POST', "/api/v1/games/{$game['id']}/room-config", [
            'room_config' => ['challenge.face.7' => 'There is no seventh face.'],
        ], ['X-Trident-Controller-Token' => $game['token']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'room_config_key_unknown');
    }

    public function test_a_game_that_does_not_exist_is_a_not_found(): void
    {
        $this->spec('0f8fad5b-d9cb-469f-a165-70867728950e')
            ->assertStatus(404)
            ->assertJsonPath('error', 'game_not_found');

        $this->spec('not-a-uuid')
            ->assertStatus(404)
            ->assertJsonPath('error', 'game_not_found');
    }

    public function test_it_carries_no_credential(): void
    {
        // It is read without a token, so it is read by anyone who has the link.
        $game = $this->createGame();

        $body = strtolower((string) $this->spec($game['id'])->assertOk()->getContent());

        foreach (['token', 'secret', 'hash', 'takeover'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }

        $this->assertStringNotContainsString(strtolower($game['token']), $body);
    }

    public function test_it_is_a_get_and_therefore_outside_the_mutation_sweeper(): void
    {
        // Confirmed against the sweeper's own filter rather than assumed: it
        // skips a game route whose only method is GET, so this one neither joins
        // the list nor becomes a registered exception to it.
        $route = collect(Route::getRoutes())->first(
            static fn ($candidate): bool => $candidate->uri() === 'api/v1/games/{gameId}/room-config-spec',
        );

        $this->assertNotNull($route, 'The route is registered.');
        $this->assertSame(
            ['GET'],
            array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS'])),
        );
        $this->assertNotContains(VerifyControllerToken::class, $route->gatherMiddleware());
    }
}
