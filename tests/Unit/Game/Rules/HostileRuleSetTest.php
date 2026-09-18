<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Src\Game\Domain\Exceptions\GameNotRunningException;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\BoardPresence;
use Src\Game\Domain\Rules\ChoiceContext;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\EffectKind;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\GameContext;
use Src\Game\Domain\Rules\Outcome;
use Src\Game\Domain\Rules\PendingChoice;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\RuleSetResolver;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\Rules\Visibility;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\TileDeck;
use Src\Game\Domain\ValueObjects\TilePool;
use Tests\Unit\Game\Doubles\HostileRuleSet;
use Tests\Unit\Game\Doubles\HostileRuleSetV2;
use Tests\Unit\Game\Doubles\RuleDriver;
use Throwable;

/**
 * The eight stresses of documentation/conventions/rule-set-seam.md, one test
 * each, named with their number.
 *
 * They exist because `trident.v1` and the seam were designed together and
 * `trident.v1` uses a narrow part of the surface. A variant sketched on paper by
 * whoever drew the interface always fits; one that has to run does not.
 *
 * **Every one of them runs through the real aggregate.** `RuleDriver` is no
 * longer a second framework loop: `Game::start()`, `Game::drawTile()` and
 * `Game::answerChoice()` take every decision below, and what is left of the
 * driver is a literal seed, a frozen clock and a chainable call. Every value
 * object, every context, every outcome and every projection is the real one too.
 *
 * **What they still do not prove.** Nothing persists a pool, a stage or a rule
 * state, and no route reaches a ruleset, so these run against the aggregate in
 * memory. The seam document asks for them to run through `GameController`, which
 * needs the columns and the routes that carry them.
 */
final class HostileRuleSetTest extends TestCase
{
    // ------------------------------------------------- 1. a mid-turn choice

    public function test_stress_1_a_rule_may_stop_the_game_mid_turn_for_a_player_choice(): void
    {
        $driver = $this->driver()->start()->draw(1);

        // The turn is not over: nobody may turn a position over, and the cursor
        // has not moved off the seat that has to answer.
        $this->assertSame(GameStatus::AWAITING_CHOICE, $driver->status());
        $this->assertFalse(GameStatus::isTerminal($driver->status()));
        $this->assertSame(1, $driver->cursor()?->value());
        $this->assertSame(
            [
                'seat' => 1,
                'prompt_key' => HostileRuleSet::PROMPT_NEXT_STAGE,
                'options' => [HostileRuleSet::OPTION_BETA, HostileRuleSet::OPTION_GAMMA],
            ],
            $driver->pendingChoice()?->toArray(),
        );

        $this->refuses(static fn () => $driver->draw(2), GameNotRunningException::class);
        $this->refuses(static fn () => $driver->choose('hostile.stage.delta'), InvalidArgumentException::class);
    }

    public function test_stress_1_the_answer_reaches_the_rules_and_changes_the_game(): void
    {
        // The same game, answered two ways, goes to two different stages: the
        // option is not decoration, it is read by the ruleset.
        foreach ([HostileRuleSet::OPTION_BETA => 'beta', HostileRuleSet::OPTION_GAMMA => 'gamma'] as $option => $stage) {
            $driver = $this->driver()->start()->draw(1)->choose($option);

            $this->assertSame(GameStatus::RUNNING, $driver->status());
            $this->assertSame($stage, $driver->stage());
            $this->assertSame($option, $driver->state()->get(HostileRuleSet::STATE_CHOSEN_STAGE));
            $this->assertNull($driver->pendingChoice());
        }
    }

    public function test_stress_1_an_answer_is_only_accepted_from_the_seat_the_choice_named(): void
    {
        $choice = PendingChoice::of(SeatNumber::fromInt(2), 'hostile.prompt', ['a', 'b']);

        $this->refuses(
            fn () => ChoiceContext::of(
                SeatNumber::fromInt(3),
                'a',
                $choice,
                $this->roster(),
                DrawLog::empty(),
                TilePool::reconstitute([], []),
                1,
                StageId::fromString('alpha'),
                RuleState::initial(1),
                RoomConfig::empty(),
            ),
            InvalidArgumentException::class,
        );
    }

    public function test_stress_1_a_pending_choice_and_a_finished_game_exclude_each_other(): void
    {
        $choice = PendingChoice::of(SeatNumber::first(), 'hostile.prompt', ['a', 'b']);

        $this->refuses(
            static fn () => Outcome::empty()->withPendingChoice($choice)->finishedBecause(FinishReason::POOL_EXHAUSTED),
            InvalidArgumentException::class,
        );
        $this->refuses(
            static fn () => Outcome::empty()->finishedBecause(FinishReason::POOL_EXHAUSTED)->withPendingChoice($choice),
            InvalidArgumentException::class,
        );
    }

    public function test_stress_1_a_pending_choice_and_an_overridden_seat_exclude_each_other(): void
    {
        // The third contradictory pair, refused like the other two rather than
        // silently dropped by whoever applies the outcome. The cursor does not
        // move while a question is open, so a seat named beside one could only be
        // honoured after the answer — and the answer's own outcome is where a
        // ruleset says who plays next.
        $choice = PendingChoice::of(SeatNumber::first(), 'hostile.prompt', ['a', 'b']);

        $this->refuses(
            static fn () => Outcome::empty()->withNextSeat(SeatNumber::fromInt(3))->withPendingChoice($choice),
            InvalidArgumentException::class,
        );
        $this->refuses(
            static fn () => Outcome::empty()->withPendingChoice($choice)->withNextSeat(SeatNumber::fromInt(3)),
            InvalidArgumentException::class,
        );
    }

    public function test_stress_1_trident_v1_never_reaches_the_third_entry_point(): void
    {
        // TR-12: no turn of the real game waits for a human, so the method exists
        // for the seam and is an incident for this ruleset.
        $this->expectException(RuntimeException::class);

        (new TridentRuleSet)->onChoiceMade(ChoiceContext::of(
            SeatNumber::first(),
            'a',
            PendingChoice::of(SeatNumber::first(), 'hostile.prompt', ['a', 'b']),
            $this->roster(),
            DrawLog::empty(),
            TilePool::reconstitute([], []),
            1,
            StageId::fromString(TridentRuleSet::STAGE_ELECTION),
            RuleState::initial(TridentRuleSet::STATE_VERSION),
            RoomConfig::empty(),
        ));
    }

    // ------------------------------------------- 2. visibility, per stage

    public function test_stress_2_the_projection_changes_shape_by_stage_and_never_by_viewer(): void
    {
        $hidden = $this->driver()->start();

        $this->assertSame('alpha', $hidden->stage());
        foreach ($hidden->projectPool() as $position) {
            $this->assertFalse($position['taken']);
            $this->assertNull($position['tile'], 'An untaken position in a hidden stage carries no face.');
        }

        // The same pool, the same projection method, one stage later: every face
        // is published, taken or not.
        $open = $this->driver()->start()->draw(1)->choose(HostileRuleSet::OPTION_BETA)->draw(1);

        $this->assertSame('beta', $open->stage());
        foreach ($open->projectPool() as $position) {
            $this->assertNotNull($position['tile'], 'Every position of an open stage carries its face.');
        }
        $this->assertSame([true, false, false, false], array_column($open->projectPool(), 'taken'));

        // And `beta` clears its board as well as opening its faces, which is the
        // pair no production ruleset asks for: the two answers are separate
        // fields of the same entry, so an open face survives a position the board
        // no longer draws.
        $this->assertSame([false, true, true, true], array_column($open->projectPool(), 'on_board'));
        $this->assertNotNull($open->projectPool()[0]['tile']);
        $this->assertSame([2, null, null, null], array_column($open->projectPool(), 'seat'));

        // `alpha` keeps everything it takes, so the flag is per stage exactly as
        // the visibility is.
        $this->assertSame(
            [],
            array_filter($hidden->projectPool(), static fn (array $entry): bool => $entry['on_board'] === false),
        );
    }

    public function test_stress_2_visibility_is_asked_of_the_stage_and_cannot_be_asked_of_a_viewer(): void
    {
        // TR-06 structurally: the only argument is a GameContext, which carries no
        // viewer, so there is no signature through which a per-spectator answer
        // could be returned.
        $method = new ReflectionMethod(RuleSet::class, 'visibility');

        $this->assertCount(1, $method->getParameters());
        $this->assertSame(GameContext::class, (string) $method->getParameters()[0]->getType());

        // And the projection is fail-closed: an unrecognised visibility hides.
        $pool = $this->driver()->start()->pool();

        $this->assertSame(
            $pool->project('not_a_visibility', BoardPresence::TAKEN_STAYS_ON_BOARD),
            $pool->project(Visibility::FACES_HIDDEN_UNTIL_TAKEN, BoardPresence::TAKEN_STAYS_ON_BOARD),
        );

        // The board's presentation is asked of the stage through the same single
        // argument, and for the same reason (TR-52).
        $presence = new ReflectionMethod(RuleSet::class, 'boardPresence');

        $this->assertCount(1, $presence->getParameters());
        $this->assertSame(GameContext::class, (string) $presence->getParameters()[0]->getType());
    }

    // ------------------------------------------------ 3. a deck of four

    public function test_stress_3_a_stage_of_four_tiles_runs_end_to_end(): void
    {
        $driver = $this->driver()->start()->draw(1)->choose(HostileRuleSet::OPTION_BETA);

        $this->assertSame(4, $driver->pool()->count());
        $this->assertCount(4, $driver->projectPool());
        $this->assertSame([1, 2, 3, 4], array_column($driver->projectPool(), 'position'));

        // Nothing downstream assumes 49: the pool serialises, reads back and
        // drains at four just as it would at any other size.
        $pool = $driver->draw(1)->draw(2)->pool();
        $roundTripped = TilePool::reconstitute($pool->tiles(), $pool->takers());

        $this->assertSame(
            $pool->project(Visibility::FACES_OPEN, BoardPresence::TAKEN_LEAVES_BOARD),
            $roundTripped->project(Visibility::FACES_OPEN, BoardPresence::TAKEN_LEAVES_BOARD),
        );
        $this->assertSame([1, 2], array_keys($roundTripped->takers()));
        $this->assertSame(2, $roundTripped->remaining());

        // Draining four positions leaves the stage exactly as draining forty-nine
        // would: the ruleset's transition fires and the framework moves on.
        $driver->draw(3)->draw(4);

        $this->assertSame('alpha', $driver->stage());
        $this->assertSame(GameStatus::RUNNING, $driver->status());
        $this->assertSame(4, count(array_filter($driver->stagesPlayed(), static fn (string $s): bool => $s === 'beta')));
    }

    public function test_stress_3_a_deck_is_any_list_of_tiles_and_never_an_empty_one(): void
    {
        $this->refuses(static fn () => TileDeck::of(), InvalidArgumentException::class);
    }

    // --------------------------------------------- 4. backwards transitions

    public function test_stress_4_next_stage_jumps_backwards_through_the_declaration_order(): void
    {
        $driver = $this->driver()->start()->draw(1)->choose(HostileRuleSet::OPTION_GAMMA)->playOut();

        $order = array_map(
            static fn (StageId $stage): string => $stage->value(),
            (new HostileRuleSet)->stages()->all(),
        );

        $this->assertSame(['alpha', 'beta', 'gamma'], $order);
        $this->assertSame(
            ['alpha', 'gamma', 'gamma', 'gamma', 'beta', 'beta', 'beta', 'beta', 'alpha', 'alpha', 'alpha', 'alpha', 'alpha', 'alpha'],
            $driver->stagesPlayed(),
        );

        // gamma -> beta and beta -> alpha both move to an earlier stage than the
        // one being left, and one of them to a stage already played.
        $visited = array_values(array_unique($driver->stagesPlayed()));

        foreach ([['gamma', 'beta'], ['beta', 'alpha']] as [$from, $to]) {
            $this->assertGreaterThan(
                array_search($to, $order, true),
                array_search($from, $order, true),
                "{$from} -> {$to} is not a backwards transition.",
            );
        }

        $this->assertSame(['alpha', 'gamma', 'beta'], $visited);
        $this->assertSame(2, $driver->visitsOf('alpha'));
        $this->assertSame(GameStatus::FINISHED, $driver->status());
    }

    public function test_stress_4_a_stage_entered_twice_is_shuffled_twice(): void
    {
        $driver = $this->driver()->start();
        $first = array_column(
            $driver->pool()->project(Visibility::FACES_OPEN, BoardPresence::TAKEN_STAYS_ON_BOARD),
            'tile',
        );

        $second = array_column(
            $driver->draw(1)->choose(HostileRuleSet::OPTION_GAMMA)->playOut(9)->pool()->project(
                Visibility::FACES_OPEN,
                BoardPresence::TAKEN_STAYS_ON_BOARD,
            ),
            'tile',
        );

        // The same stage, the same seed, a second visit: a ruleset that points
        // backwards must not deal the identical pool in the identical order.
        $this->assertSame('alpha', $driver->stage());
        $this->assertCount(count($first), $second);
        $this->assertNotSame($first, $second, 'A revisited stage was dealt in the same order.');

        // The same tiles, a different order: it is a reshuffle and not a different
        // deck.
        sort($first);
        sort($second);
        $this->assertSame($first, $second);
    }

    // ------------------------------------------------- 5. a game of no draws

    public function test_stress_5_on_game_started_may_finish_a_game_of_zero_draws(): void
    {
        $driver = RuleDriver::of(new HostileRuleSet, $this->roster(HostileRuleSet::MAX_SEATS + 1))->start();

        $this->assertSame(GameStatus::FINISHED, $driver->status());
        $this->assertTrue(GameStatus::isTerminal($driver->status()));
        $this->assertSame(FinishReason::RULES_ENDED_GAME, $driver->finishReason());
        $this->assertSame([], $driver->history());
        $this->assertSame(0, $driver->turnNumber());
        $this->assertSame([], $driver->effects());
    }

    // ------------------------------------------- 6. the same seat draws again

    public function test_stress_6_override_next_seat_may_name_the_seat_that_just_drew(): void
    {
        // The hand-off screen has no design for "do not pass it, Ana goes again":
        // four consecutive draws by one seat is a frontend consequence of this
        // field, reported with the change that wrote this test.
        $driver = $this->driver()->start()->draw(1)->choose(HostileRuleSet::OPTION_BETA)->playOut();

        $beta = array_values(array_filter(
            $driver->history(),
            static fn (array $draw): bool => $draw['stage'] === 'beta',
        ));

        $this->assertCount(4, $beta);
        $this->assertSame([2, 2, 2, 2], array_column($beta, 'seat'));
    }

    // ------------------------------------------------ 7. a rule reads a setting

    public function test_stress_7_a_rule_branches_on_a_value_the_table_wrote(): void
    {
        $short = $this->driver()->start()->draw(1)->choose(HostileRuleSet::OPTION_GAMMA);
        $long = $this->driver([HostileRuleSet::CONFIG_GAMMA_DECK => HostileRuleSet::GAMMA_DECK_LONG])
            ->start()->draw(1)->choose(HostileRuleSet::OPTION_GAMMA);

        $this->assertSame(3, $short->pool()->count());
        $this->assertSame(6, $long->pool()->count());
    }

    public function test_stress_7_a_malformed_stored_table_falls_back_to_every_default(): void
    {
        // Undeclared, wrong-typed, over-long: room-config.md's tolerant reader
        // fills each one with its default and the game plays on. Nothing here
        // throws, and nothing is coerced.
        $driver = $this->driver([
            HostileRuleSet::CONFIG_GAMMA_DECK => 'enormous',
            HostileRuleSet::CONFIG_BANNER => str_repeat('x', HostileRuleSet::BANNER_MAX_LENGTH + 1),
            HostileRuleSet::CONFIG_LOOP => 'yes',
            'hostile.undeclared' => 1,
        ])->start()->draw(1)->choose(HostileRuleSet::OPTION_GAMMA);

        $this->assertSame(3, $driver->pool()->count(), 'A value the spec refuses takes the default.');

        $driver->playOut();

        $this->assertSame(GameStatus::FINISHED, $driver->status());
        $this->assertSame(FinishReason::POOL_EXHAUSTED, $driver->finishReason());
        $this->assertSame(2, $driver->visitsOf('alpha'), 'The loop default survived a malformed table.');
    }

    public function test_stress_7_a_setting_the_table_did_write_changes_where_the_game_ends(): void
    {
        $driver = $this->driver([HostileRuleSet::CONFIG_LOOP => false])
            ->start()->draw(1)->choose(HostileRuleSet::OPTION_BETA)->playOut();

        $this->assertSame(GameStatus::FINISHED, $driver->status());
        $this->assertSame(FinishReason::RULES_ENDED_GAME, $driver->finishReason());
        $this->assertSame(1, $driver->visitsOf('alpha'));
    }

    // ----------------------------------------- 8. the same id, one version on

    public function test_stress_8_a_later_version_reading_an_older_state_ends_the_game(): void
    {
        $game = $this->driver()->start()->draw(1)->choose(HostileRuleSet::OPTION_GAMMA);

        $this->assertSame(HostileRuleSet::STATE_VERSION, $game->state()->toArray()[RuleState::VERSION_KEY]);
        $this->assertSame(HostileRuleSet::ID, (new HostileRuleSetV2)->id());
        $this->assertSame(HostileRuleSet::STATE_VERSION + 1, (new HostileRuleSetV2)->stateVersion());

        // One game, mid-play, handed the next deployment of the ruleset it is
        // pinned to: the id still matches, so the aggregate plays on and the
        // blob it hands over is one this version did not write.
        $upgraded = $game->upgradedTo(new HostileRuleSetV2)->draw(1);

        $this->assertSame(GameStatus::FINISHED, $upgraded->status());
        $this->assertSame(FinishReason::RULESET_UPGRADED, $upgraded->finishReason());
        $this->assertSame(
            [],
            $upgraded->game()->lastEffects(),
            'The game ends instead of acting on a state it cannot read.',
        );
    }

    public function test_stress_8_the_same_version_plays_on(): void
    {
        $fresh = RuleDriver::of(new HostileRuleSetV2, $this->roster())->start()->draw(1);

        $this->assertSame(GameStatus::RUNNING, $fresh->status());
        $this->assertNull($fresh->finishReason());
        $this->assertSame(EffectKind::CHALLENGE, $fresh->effects()[0]->kind());
    }

    public function test_stress_8_two_versions_never_serve_one_id_at_once(): void
    {
        // An upgrade is a replacement: the resolver reads `games.rule_set_id`, so
        // two rulesets answering to one id is a deployment mistake and not a
        // choice the framework makes at runtime.
        $this->refuses(
            static fn () => new RuleSetResolver([new HostileRuleSet, new HostileRuleSetV2]),
            InvalidArgumentException::class,
        );

        $resolver = new RuleSetResolver([new HostileRuleSetV2, new TridentRuleSet]);

        $this->assertSame([HostileRuleSet::ID, TridentRuleSet::ID], $resolver->ids());
        $this->assertInstanceOf(HostileRuleSetV2::class, $resolver->resolve(HostileRuleSet::ID));
    }

    // ------------------------------------------------------ the whole surface

    public function test_the_hostile_ruleset_exercises_what_trident_v1_does_not(): void
    {
        $driver = $this->driver()->start()->draw(1)->choose(HostileRuleSet::OPTION_GAMMA)->playOut();

        // Every member of Outcome that the production ruleset never fills.
        $this->assertNotSame([], $driver->state()->toArray());
        $this->assertArrayHasKey(HostileRuleSet::STATE_LAST_GAMMA_TILE, $driver->state()->toArray());
        $this->assertSame(HostileRuleSet::STATE_VERSION, $driver->state()->version());

        // One effect kind, one recipient each, and never a counter of anything.
        foreach ($driver->effects() as $effect) {
            $this->assertInstanceOf(Effect::class, $effect);
            $this->assertSame(EffectKind::CHALLENGE, $effect->kind());
            $this->assertSame(HostileRuleSet::CONFIG_BANNER, $effect->configKey());
        }

        // Fourteen draws and fourteen effects: the draw that parked the turn
        // emitted none, and answering the question emitted one.
        $this->assertSame(14, $driver->turnNumber());
        $this->assertCount(14, $driver->effects());
    }

    public function test_an_unknown_stage_is_an_incident_and_never_the_strictest_branch(): void
    {
        $this->expectException(RuntimeException::class);

        (new HostileRuleSet)->deck(GameContext::of(
            StageId::fromString('delta'),
            $this->roster(),
            null,
            DrawLog::empty(),
            0,
            RuleState::initial(HostileRuleSet::STATE_VERSION),
            RoomConfig::empty(),
        ));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $storedRoomConfig
     */
    private function driver(array $storedRoomConfig = [], int $seats = 3): RuleDriver
    {
        return RuleDriver::of(new HostileRuleSet, $this->roster($seats), $storedRoomConfig);
    }

    private function roster(int $seats = 3): SeatRoster
    {
        $list = [];

        for ($number = 1; $number <= $seats; $number++) {
            $list[] = Seat::of(SeatNumber::fromInt($number), Nickname::fromString("Player {$number}"));
        }

        return SeatRoster::fromSeats($list);
    }

    /**
     * @param  callable(): mixed  $call
     * @param  class-string<Throwable>  $expected
     */
    private function refuses(callable $call, string $expected): void
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($expected, $thrown);

            return;
        }

        self::fail("Expected {$expected} and nothing was thrown.");
    }
}
