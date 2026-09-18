<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Testing\TestResponse;
use Src\Game\Application\Service\GameProjector;
use Src\Game\Application\Service\GameRules;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\RuleSetResolver;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\GameStatus;
use Tests\Doubles\InMemoryGameRepository;
use Tests\Support\GameProjectorFactory;
use Tests\TestCase;
use Tests\Unit\Game\Doubles\HostileRuleSet;

/**
 * The hostile ruleset, **through `GameController` and the real handlers**, which
 * documentation/conventions/rule-set-seam.md has been demanding since the seam
 * was written: running it against the aggregate proves the aggregate, not the
 * seam's delivery.
 *
 * Nothing in the wiring below is a change to the ruleset or to a route. What is
 * swapped is the registry of rulesets and which one a new game is pinned to,
 * which is exactly the swap a house variant would be.
 *
 * Four of the eight stresses reach a route: a deck that is not 49, a table the
 * variant refuses to play at all, a turn parked on a question, and a
 * configuration form generated from the variant's own keys. The other four need
 * either an answer to that question — no route reaches `answerChoice()` — or a
 * second registration of the same id, and both are noted rather than implied.
 */
final class HostileSeamOverHttpTest extends TestCase
{
    private InMemoryGameRepository $games;

    private string $gameId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->games = new InMemoryGameRepository;
        $this->app->instance(GameRepository::class, $this->games);

        // One registry, read by both the writer's side and the projection's: a
        // game pinned to an id is played and painted by the same object.
        $resolver = new RuleSetResolver([new HostileRuleSet]);

        $this->app->instance(GameRules::class, new GameRules($resolver, HostileRuleSet::ID));
        $this->app->instance(
            GameProjector::class,
            new GameProjector($resolver, GameProjectorFactory::TV_IDLE_NOTICE_MINUTES),
        );
    }

    /**
     * @param  list<string>  $nicknames
     */
    private function tableOf(array $nicknames): void
    {
        $created = $this->postJson('/api/v1/games', ['nicknames' => $nicknames])->assertCreated();

        $this->gameId = (string) $created->json('data.game.game_id');
        $this->token = (string) $created->json('data.controller_token');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function write(string $method, string $path, array $body = []): TestResponse
    {
        return $this->json($method, "/api/v1/games/{$this->gameId}{$path}", $body, [
            'X-Trident-Controller-Token' => $this->token,
        ]);
    }

    public function test_the_third_stress_a_deck_that_is_not_forty_nine_is_dealt_over_http(): void
    {
        // Nothing between the ruleset and the wire assumes 49 because the Trident
        // deals 49: not the pool's serialisation, not the envelope, not the route.
        $this->tableOf(['Ana', 'Bea', 'Caro']);

        $data = (array) $this->write('POST', '/start')->assertOk()->json('data');

        $this->assertSame(GameStatus::RUNNING, $data['status']);
        $this->assertSame(HostileRuleSet::STAGE_ALPHA, $data['stage']);
        $this->assertSame(1, $data['current_seat']);
        $this->assertCount(6, $data['pool']);
    }

    public function test_the_fifth_stress_a_game_of_zero_draws_terminates_through_the_route(): void
    {
        // A variant that refuses the table in front of it. The terminal path does
        // not require a draw, and the code is released on the way out.
        $this->tableOf(['Ana', 'Bea', 'Caro', 'Dani', 'Eva', 'Fran']);

        $data = (array) $this->write('POST', '/start')->assertOk()->json('data');

        $this->assertSame(GameStatus::FINISHED, $data['status']);
        $this->assertNull($data['join_code'], 'A finished game cannot be joined from anywhere.');
        $this->assertSame(2, $data['version']);
    }

    public function test_the_first_stress_a_turn_parks_on_a_question_and_the_cursor_stays_put(): void
    {
        $this->tableOf(['Ana', 'Bea', 'Caro']);
        $this->write('POST', '/start')->assertOk();

        $data = (array) $this->write('POST', '/pool/1/draw')->assertOk()->json('data');

        $this->assertSame(GameStatus::AWAITING_CHOICE, $data['status']);
        $this->assertSame(1, $data['current_seat'], 'The turn is not over until the question is answered.');
        $this->assertTrue($data['pool'][0]['taken']);
    }

    public function test_a_parked_game_refuses_the_next_position_over_http(): void
    {
        // The status exists so that a rule mid-decision cannot have the next
        // position turned over underneath it.
        $this->tableOf(['Ana', 'Bea', 'Caro']);
        $this->write('POST', '/start')->assertOk();
        $this->write('POST', '/pool/1/draw')->assertOk();

        $this->write('POST', '/pool/2/draw')
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_not_running');
    }

    public function test_the_seventh_stress_the_form_is_generated_from_this_variants_own_keys(): void
    {
        // The route validates against a spec it does not understand: no class of
        // the framework and no line of HTTP knows what any of these keys mean.
        $this->tableOf(['Ana', 'Bea', 'Caro']);

        $this->write('POST', '/room-config', [
            'room_config' => [
                HostileRuleSet::CONFIG_GAMMA_DECK => HostileRuleSet::GAMMA_DECK_LONG,
                HostileRuleSet::CONFIG_LOOP => false,
            ],
        ])->assertOk()->assertJsonPath('data.version', 2);

        $saved = (array) $this->write('GET', '')->assertOk()->json('data.room_config');

        $this->assertSame(HostileRuleSet::GAMMA_DECK_LONG, $saved[HostileRuleSet::CONFIG_GAMMA_DECK] ?? null);
        $this->assertFalse($saved[HostileRuleSet::CONFIG_LOOP] ?? null);
    }

    public function test_a_key_of_the_production_ruleset_is_unknown_to_this_one(): void
    {
        // The proof that the key space belongs to the ruleset and not to the
        // route: the seven keys of the real game do not exist here.
        $this->tableOf(['Ana', 'Bea', 'Caro']);

        $this->write('POST', '/room-config', [
            'room_config' => [TridentRuleSet::challengeKey(0) => 'Not this variant'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'room_config_key_unknown');
    }

    public function test_a_toggle_is_a_boolean_and_is_never_coerced_over_the_wire(): void
    {
        $this->tableOf(['Ana', 'Bea', 'Caro']);

        $this->write('POST', '/room-config', ['room_config' => [HostileRuleSet::CONFIG_LOOP => 'true']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'room_config_value_invalid')
            ->assertJsonPath('data.keys', [HostileRuleSet::CONFIG_LOOP]);
    }

    public function test_starting_resolves_every_key_this_variant_declares(): void
    {
        // A stored blob missing a declared key is filled at the one resolution
        // point, so the client may assume every declared key is present.
        $this->tableOf(['Ana', 'Bea', 'Caro']);

        $this->write('POST', '/room-config', [
            'room_config' => [HostileRuleSet::CONFIG_BANNER => 'Hand it over'],
        ])->assertOk();

        $resolved = (array) $this->write('POST', '/start')->assertOk()->json('data.room_config');

        $ruleSet = new HostileRuleSet;
        $declared = $ruleSet->roomConfigSpec()->keys();
        sort($declared);

        // Sorted, because the projection imposes an order on a map: the order of
        // a map is not information, and the two delivery paths have to agree.
        $this->assertSame($declared, array_keys($resolved));
        $this->assertSame('Hand it over', $resolved[HostileRuleSet::CONFIG_BANNER]);
    }
}
