<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRing;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\BoardPresence;
use Src\Game\Domain\Rules\DrawContext;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\EffectKind;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\GameContext;
use Src\Game\Domain\Rules\Outcome;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\Rules\Visibility;
use Src\Game\Domain\ValueObjects\Draw;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TileDeck;
use Src\Game\Domain\ValueObjects\TilePool;
use Src\Shared\Domain\Service\Clock;
use Tests\Unit\Game\Doubles\RuleDriver;

/**
 * The rules of the Trident, one test per numbered assertion of
 * documentation/conventions/trident-rules.md, named with its number, so that the
 * coverage of the specification is audited by reading the two lists side by
 * side. Four numbers are claims of an object other than the ruleset and carry
 * their number in that object's own test instead: TR-08 in `TilePoolTest`, TR-09
 * in `SeedTest`, TR-15 in `NicknameTest` and TR-55 in
 * `MigrationPortabilityTest`. `SpecificationCoverageTest` reads both lists and
 * fails on a number with no test, so the audit is a test and not a reading.
 *
 * Nothing depends on a lucky shuffle: every pool is materialised from one
 * literal `Seed`, so a stage played here is the same stage on every machine and
 * on every run.
 */
final class TridentRuleSetTest extends TestCase
{
    /** A literal seed: a test that depends on chance proves nothing. */
    private const SEED = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

    private const ELECTION = TridentRuleSet::STAGE_ELECTION;

    private const MAIN = TridentRuleSet::STAGE_MAIN;

    private const DOUBLE_THREE = '33';

    // ---------------------------------------------------------------- the deck

    public function test_tr_01_every_stage_plays_the_49_ordered_pairs(): void
    {
        $standard = $this->faces(TileDeck::standard());

        $this->assertCount(49, $standard);
        $this->assertSame($standard, $this->faces($this->ruleSet()->deck($this->gameContext(self::ELECTION))));
        $this->assertSame($standard, $this->faces($this->ruleSet()->deck($this->gameContext(self::MAIN))));
    }

    public function test_tr_02_a_tile_is_an_ordered_pair_and_its_faces_fire_in_that_order(): void
    {
        $faces = $this->faces($this->ruleSet()->deck($this->gameContext(self::ELECTION)));

        $this->assertContains('21', $faces);
        $this->assertContains('12', $faces);

        // Asserted in `main`, the stage that fires anything (TR-23).
        $this->assertSame(
            ['challenge.face.2', 'challenge.face.1'],
            $this->configKeys($this->drawIn(self::MAIN, Tile::fromString('21'))),
        );
        $this->assertSame(
            ['challenge.face.1', 'challenge.face.2'],
            $this->configKeys($this->drawIn(self::MAIN, Tile::fromString('12'))),
        );
    }

    public function test_tr_03_the_deck_holds_one_double_three_and_one_of_every_double(): void
    {
        $faces = $this->faces($this->ruleSet()->deck($this->gameContext(self::ELECTION)));

        $this->assertCount(1, array_keys($faces, self::DOUBLE_THREE, true));

        for ($face = 0; $face <= TridentRuleSet::MAX_FACE; $face++) {
            $this->assertCount(1, array_keys($faces, "{$face}{$face}", true));
        }
    }

    public function test_tr_04_each_stage_materialises_its_own_deck(): void
    {
        // The double three has already come up in the election, and main asks for
        // a deck that still holds it: a draw is identified by (stage, position)
        // and never by its tile.
        $drawn = DrawLog::of([
            Draw::of(self::ELECTION, SeatNumber::first(), PoolPosition::first(), Tile::fromString(self::DOUBLE_THREE)),
        ]);

        $context = GameContext::of(
            StageId::fromString(self::MAIN),
            $this->roster(),
            SeatNumber::first(),
            $drawn,
            1,
            RuleState::initial(TridentRuleSet::STATE_VERSION),
            $this->roomConfig(),
        );

        $this->assertContains(self::DOUBLE_THREE, $this->faces($this->ruleSet()->deck($context)));
        $this->assertCount(49, $this->faces($this->ruleSet()->deck($context)));
    }

    public function test_tr_05_the_deck_does_not_depend_on_the_number_of_seats(): void
    {
        $this->assertSame(
            $this->faces($this->ruleSet()->deck($this->gameContext(self::MAIN, $this->roster(SeatRoster::MIN_SEATS)))),
            $this->faces($this->ruleSet()->deck($this->gameContext(self::MAIN, $this->roster(SeatRoster::MAX_SEATS)))),
        );
    }

    // ------------------------------------------------- no hidden information

    public function test_tr_06_visibility_is_asked_per_stage_and_never_per_viewer(): void
    {
        $parameters = (new ReflectionClass(RuleSet::class))->getMethod('visibility')->getParameters();
        $type = $parameters[0]->getType();

        $this->assertCount(1, $parameters);
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(GameContext::class, $type->getName());
    }

    public function test_tr_07_untaken_faces_are_hidden_in_both_stages(): void
    {
        $this->assertSame(
            Visibility::FACES_HIDDEN_UNTIL_TAKEN,
            $this->ruleSet()->visibility($this->gameContext(self::ELECTION)),
        );
        $this->assertSame(
            Visibility::FACES_HIDDEN_UNTIL_TAKEN,
            $this->ruleSet()->visibility($this->gameContext(self::MAIN)),
        );
    }

    // ------------------------------------------------------ one seat at a time

    public function test_tr_12_no_draw_ever_waits_for_another_player(): void
    {
        // The vocabulary carries a pending choice — the hostile double of
        // tests/Unit/Game/Doubles/ demanded it — and this ruleset never returns
        // one, so no status of a real game ever leaves `running` to wait for a
        // human. Every outcome is complete when it is returned, and the only
        // things it carries are effects a screen paints at once.
        foreach ($this->fullGame() as $turn) {
            $this->assertNull($turn['outcome']->pendingChoice());
            $this->assertSame([], $turn['outcome']->ruleStatePatch());
        }

        foreach ($this->game()['main'] as $turn) {
            $this->assertNotEmpty($turn['outcome']->effects());
        }

        $this->assertNull($this->ruleSet()->onGameStarted($this->gameContext(self::ELECTION))->pendingChoice());
    }

    public function test_tr_13_no_effect_asks_a_player_to_name_another(): void
    {
        // An effect carries one recipient, and this ruleset always names one: a
        // rule of the game never addresses the whole table, and never two seats.
        foreach ($this->effectsOf($this->fullGame()) as $effect) {
            $this->assertNotNull($effect->seat());
            $this->assertSame(
                ['seat'],
                array_values(array_intersect(array_keys($effect->toArray()), ['seat', 'seats', 'target', 'targets', 'from', 'to'])),
            );
        }
    }

    public function test_tr_14_every_table_size_plays_both_stages(): void
    {
        foreach ([SeatRoster::MIN_SEATS, SeatRoster::MAX_SEATS] as $seatCount) {
            $game = $this->game($seatCount);
            $drawers = array_map(static fn (array $turn): int => $turn['seat']->value(), $game['main']);

            $this->assertNotNull($this->lastOf($game['election'])['outcome']->nextStage());
            $this->assertTrue($this->lastOf($game['main'])['outcome']->isFinished());
            $this->assertSame(range(1, $seatCount), array_values(array_unique(array_slice($drawers, 0, $seatCount))));
        }
    }

    // ---------------------------------------------------------- the two stages

    public function test_tr_16_it_declares_election_then_main(): void
    {
        $this->assertSame(
            [['id' => self::ELECTION, 'label' => 'Election'], ['id' => self::MAIN, 'label' => 'Main game']],
            $this->ruleSet()->stages()->toArray(),
        );
    }

    public function test_tr_16_a_stage_it_never_declared_is_an_incident(): void
    {
        // The dispatch of onTileDrawn() has no else: a stage id that is neither
        // of the two it declares raises, rather than falling through to `main`,
        // which is the branch that needs a trident and ends the game on
        // exhaustion (TR-28, TR-34).
        //
        // The roster carries a trident and the pool is full on purpose: played as
        // `main` this draw succeeds, so the only exception left is the intended
        // one, and the message says which.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'bonus' is not a stage of");

        $this->ruleSet()->onTileDrawn($this->drawContext(
            'bonus',
            Tile::fromString('34'),
            SeatNumber::first(),
            $this->roster(5, 2),
            $this->pool(self::ELECTION),
            PoolPosition::first(),
            1,
        ));
    }

    public function test_tr_17_a_started_game_stands_in_the_election(): void
    {
        $outcome = $this->ruleSet()->onGameStarted($this->gameContext(self::ELECTION));

        $this->assertSame(self::ELECTION, $this->ruleSet()->stages()->first()->value());
        $this->assertNull($outcome->nextStage());
        $this->assertFalse($outcome->isFinished());
        $this->assertSame([], $outcome->effects());
    }

    public function test_tr_18_the_only_stage_transition_is_the_election_into_main(): void
    {
        $stages = [];

        foreach ($this->fullGame() as $turn) {
            if ($turn['outcome']->nextStage() !== null) {
                $stages[] = $turn['outcome']->nextStage()->value();
            }
        }

        $this->assertSame([self::MAIN], $stages);
    }

    public function test_tr_19_it_keeps_nothing_in_the_rule_state_beyond_the_version(): void
    {
        $this->assertSame(1, $this->ruleSet()->stateVersion());
        $this->assertSame(
            [],
            $this->ruleSet()->onGameStarted($this->gameContext(self::ELECTION))->ruleStatePatch(),
        );

        foreach ($this->fullGame() as $turn) {
            $this->assertSame([], $turn['outcome']->ruleStatePatch());
        }
    }

    // ------------------------------------------------------------ the election

    public function test_tr_20_the_election_opens_a_pool_of_49_positions(): void
    {
        $this->assertSame(49, $this->pool(self::ELECTION)->count());
        $this->assertSame(49, $this->pool(self::ELECTION)->remaining());
    }

    public function test_tr_21_seat_one_draws_first_in_the_election(): void
    {
        $outcome = $this->ruleSet()->onGameStarted($this->gameContext(self::ELECTION));

        $this->assertNotNull($outcome->overrideNextSeat());
        $this->assertSame(1, $outcome->overrideNextSeat()->value());
    }

    public function test_tr_22_the_election_advances_in_ring_order_and_overrides_only_on_the_double_three(): void
    {
        $turns = $this->play(self::ELECTION, $this->roster());
        $seats = array_map(static fn (array $turn): int => $turn['seat']->value(), $turns);

        // The ring rotates 1, 2, 3, 4, 5, 1, … with no skip and no repetition.
        $this->assertSame(
            array_map(static fn (int $index): int => $index % 5 + 1, range(0, count($turns) - 1)),
            $seats,
        );

        foreach (array_slice($turns, 0, -1) as $turn) {
            $this->assertNull($turn['outcome']->overrideNextSeat());
        }

        $this->assertNotNull($this->lastOf($turns)['outcome']->overrideNextSeat());
    }

    public function test_tr_23_the_election_fires_no_challenge_at_all(): void
    {
        $turns = $this->play(self::ELECTION, $this->roster());

        // Every draw but the last produces a completely empty outcome, and the
        // last one produces the role and still no challenge: a face of three
        // reached in the election is a face like any other, and it says nothing.
        foreach (array_slice($turns, 0, -1) as $turn) {
            $this->assertSame([], $turn['outcome']->effects());
        }

        $this->assertSame([], $this->configKeysOf($turns));

        // Asserted against a tile that carries the face the game does treat
        // specially, so a stage that leaked the main rule would be caught here
        // and not only in the fixture the shuffle happened to deal.
        $this->assertSame([], $this->configKeys($this->drawIn(self::ELECTION, Tile::fromString('36'))));
        $this->assertSame([], $this->configKeys($this->drawIn(self::ELECTION, Tile::fromString('30'), SeatNumber::fromInt(4), $this->roster(5, 2))));
    }

    public function test_tr_24_the_election_ends_on_the_double_three(): void
    {
        $turns = $this->play(self::ELECTION, $this->roster());
        $last = $this->lastOf($turns);

        $this->assertSame(self::DOUBLE_THREE, $last['tile']->value());
        $this->assertSame(self::MAIN, $last['outcome']->nextStage()?->value());

        foreach (array_slice($turns, 0, -1) as $turn) {
            $this->assertNotSame(self::DOUBLE_THREE, $turn['tile']->value());
            $this->assertNull($turn['outcome']->nextStage());
        }
    }

    public function test_tr_25_the_rest_of_the_election_pool_is_never_played(): void
    {
        $turns = $this->play(self::ELECTION, $this->roster());
        $unplayed = $this->pool(self::ELECTION)->count() - count($turns);

        // The election stopped with tiles left, and main is dealt a whole deck
        // regardless of what the election turned over.
        $this->assertGreaterThan(0, $unplayed);
        $this->assertCount(49, $this->faces($this->ruleSet()->deck($this->gameContext(self::MAIN))));
    }

    public function test_tr_26_the_election_cannot_run_out(): void
    {
        $pool = $this->pool(self::ELECTION);
        $position = $this->positionOf($pool, Tile::fromString(self::DOUBLE_THREE));

        // The shortest election: the double three on the first draw.
        $first = $this->ruleSet()->onTileDrawn($this->drawContext(
            self::ELECTION,
            Tile::fromString(self::DOUBLE_THREE),
            SeatNumber::first(),
            $this->roster(),
            $pool,
            $position,
            1,
        ));

        // The longest: the double three as the only position still untaken.
        $last = $this->ruleSet()->onTileDrawn($this->drawContext(
            self::ELECTION,
            Tile::fromString(self::DOUBLE_THREE),
            SeatNumber::first(),
            $this->roster(),
            $this->emptiedExcept($pool, $position),
            $position,
            49,
        ));

        $this->assertSame(self::MAIN, $first->nextStage()?->value());
        $this->assertSame(self::MAIN, $last->nextStage()?->value());
        $this->assertFalse($last->isFinished());
        $this->assertNull($last->finishReason());
    }

    // -------------------------------------------------------------- the trident

    public function test_tr_27_whoever_draws_the_double_three_becomes_the_trident(): void
    {
        $outcome = $this->drawIn(self::ELECTION, Tile::fromString(self::DOUBLE_THREE), SeatNumber::fromInt(4));
        $effect = $outcome->effects()[0];

        $this->assertSame(EffectKind::ASSIGN_ROLE, $effect->kind());
        $this->assertSame(4, $effect->seat()?->value());
        $this->assertSame(TridentRuleSet::ROLE_TRIDENT, $effect->role());
        $this->assertSame(
            ['kind' => 'assign_role', 'seat' => 4, 'role' => 'trident'],
            $effect->toArray(),
        );
    }

    public function test_tr_28_there_is_exactly_one_trident_in_a_game(): void
    {
        $roles = array_filter(
            $this->effectsOf($this->fullGame()),
            static fn (Effect $effect): bool => $effect->kind() === EffectKind::ASSIGN_ROLE,
        );

        $this->assertCount(1, $roles);
    }

    public function test_tr_29_the_trident_is_read_from_the_seats(): void
    {
        // Moving the role to another seat moves the recipient of the face of
        // three with it: there is no field of its own anywhere to read instead.
        $tile = Tile::fromString('34');

        $this->assertSame(
            2,
            $this->drawIn(self::MAIN, $tile, SeatNumber::first(), $this->roster(5, 2))->effects()[0]->seat()?->value(),
        );
        $this->assertSame(
            5,
            $this->drawIn(self::MAIN, $tile, SeatNumber::first(), $this->roster(5, 5))->effects()[0]->seat()?->value(),
        );
    }

    public function test_tr_30_being_the_trident_changes_nobody_s_turn(): void
    {
        $tile = Tile::fromString('34');

        foreach ([SeatNumber::fromInt(2), SeatNumber::fromInt(3)] as $drawer) {
            $outcome = $this->drawIn(self::MAIN, $tile, $drawer, $this->roster(5, 2));

            $this->assertNull($outcome->overrideNextSeat());
            $this->assertCount(2, $outcome->effects());
        }
    }

    // -------------------------------------------------------------- the main game

    public function test_tr_31_main_opens_a_fresh_pool_of_49_positions(): void
    {
        $this->assertSame(49, $this->pool(self::MAIN)->count());
        $this->assertSame(49, $this->pool(self::MAIN)->remaining());

        // Each stage draws its own order from the one seed.
        $this->assertNotSame($this->faces($this->pool(self::ELECTION)), $this->faces($this->pool(self::MAIN)));
    }

    public function test_tr_32_seat_one_opens_main_whoever_the_trident_is(): void
    {
        foreach ([1, 3, 5] as $elector) {
            $outcome = $this->drawIn(
                self::ELECTION,
                Tile::fromString(self::DOUBLE_THREE),
                SeatNumber::fromInt($elector),
            );

            $this->assertSame(1, $outcome->overrideNextSeat()?->value());
        }
    }

    public function test_tr_33_main_advances_in_ring_order_with_no_override(): void
    {
        $turns = $this->game()['main'];

        foreach ($turns as $turn) {
            $this->assertNull($turn['outcome']->overrideNextSeat());
        }

        $this->assertSame(
            array_map(static fn (int $index): int => $index % 5 + 1, range(0, count($turns) - 1)),
            array_map(static fn (array $turn): int => $turn['seat']->value(), $turns),
        );
    }

    public function test_tr_34_main_ends_when_its_pool_runs_out(): void
    {
        $last = $this->lastOf($this->game()['main']);

        $this->assertTrue($last['outcome']->isFinished());
        $this->assertSame(FinishReason::POOL_EXHAUSTED, $last['outcome']->finishReason());
        $this->assertSame(49, $last['position']->value());
        $this->assertNull($last['outcome']->nextStage());
    }

    public function test_tr_35_nothing_else_ends_the_game_by_rule(): void
    {
        $game = $this->game();

        foreach ($game['election'] as $turn) {
            $this->assertFalse($turn['outcome']->isFinished());
        }

        foreach (array_slice($game['main'], 0, -1) as $turn) {
            $this->assertFalse($turn['outcome']->isFinished());
        }

        $this->assertTrue($this->lastOf($game['main'])['outcome']->isFinished());
    }

    public function test_tr_36_main_lasts_exactly_49_draws_at_every_table_size(): void
    {
        foreach ([SeatRoster::MIN_SEATS, 5, SeatRoster::MAX_SEATS] as $seatCount) {
            $this->assertCount(49, $this->game($seatCount)['main']);
        }
    }

    public function test_tr_37_nobody_is_eliminated(): void
    {
        $turns = $this->game()['main'];
        $opening = array_slice(array_map(static fn (array $turn): int => $turn['seat']->value(), $turns), 0, 5);
        $closing = array_map(static fn (array $turn): int => $turn['seat']->value(), array_slice($turns, -5));

        sort($opening);
        sort($closing);

        $this->assertSame($opening, $closing);
    }

    // ------------------------------------------------------------- the challenges

    public function test_tr_38_every_flipped_tile_fires_exactly_two_challenges(): void
    {
        $game = $this->game();

        foreach ($game['main'] as $turn) {
            $this->assertCount(2, $this->configKeys($turn['outcome']));
        }

        // And the election fires none of them (TR-23), which is what makes this
        // a claim about one stage rather than about every draw of the game.
        $this->assertSame([], $this->configKeysOf($game['election']));
    }

    public function test_tr_39_the_left_face_fires_before_the_right_one(): void
    {
        $this->assertSame(
            ['challenge.face.2', 'challenge.face.1'],
            $this->configKeys($this->drawIn(self::MAIN, Tile::fromString('21'))),
        );

        foreach ($this->game()['main'] as $turn) {
            $this->assertSame(
                [
                    TridentRuleSet::challengeKey($turn['tile']->left()),
                    TridentRuleSet::challengeKey($turn['tile']->right()),
                ],
                $this->configKeys($turn['outcome']),
            );
        }
    }

    public function test_tr_41_a_double_fires_the_challenge_of_its_face_twice(): void
    {
        for ($face = 0; $face <= TridentRuleSet::MAX_FACE; $face++) {
            $outcome = $this->drawIn(self::MAIN, Tile::of($face, $face), SeatNumber::first(), $this->roster(5, 2));

            $this->assertSame(
                [TridentRuleSet::challengeKey($face), TridentRuleSet::challengeKey($face)],
                $this->configKeys($outcome),
            );
        }
    }

    public function test_tr_42_a_challenge_carries_the_key_and_never_the_text(): void
    {
        $outcome = $this->drawIn(self::MAIN, Tile::fromString('30'), SeatNumber::fromInt(4), $this->roster(5, 1));

        $this->assertSame(
            [
                ['kind' => 'challenge', 'seat' => 1, 'config_key' => 'challenge.face.3'],
                ['kind' => 'challenge', 'seat' => 4, 'config_key' => 'challenge.face.0'],
            ],
            array_map(static fn (Effect $effect): array => $effect->toArray(), $outcome->effects()),
        );
    }

    public function test_tr_43_there_are_exactly_seven_challenge_keys_and_none_is_indexed_by_tile(): void
    {
        $declared = array_values(array_filter(
            $this->ruleSet()->roomConfigSpec()->keys(),
            static fn (string $key): bool => str_starts_with($key, TridentRuleSet::CHALLENGE_KEY_PREFIX),
        ));

        // Written out rather than generated: TR-43 says seven, so seven keys are
        // the assertion and not a loop that agrees with whatever the code does.
        $this->assertSame(
            [
                'challenge.face.0',
                'challenge.face.1',
                'challenge.face.2',
                'challenge.face.3',
                'challenge.face.4',
                'challenge.face.5',
                'challenge.face.6',
            ],
            $declared,
        );

        foreach ($this->configKeysOf($this->fullGame()) as $key) {
            $this->assertContains($key, $declared);
        }
    }

    public function test_tr_44_in_main_the_face_of_three_is_answered_by_the_trident(): void
    {
        $outcome = $this->drawIn(self::MAIN, Tile::fromString('53'), SeatNumber::fromInt(4), $this->roster(5, 2));

        $this->assertSame(
            [['challenge.face.5', 4], ['challenge.face.3', 2]],
            $this->targets($outcome),
        );
    }

    public function test_tr_44_a_main_stage_without_a_trident_is_an_incident(): void
    {
        // Quietly retargeting the fourteen challenges of a stage at the drawer
        // would be a game nobody could tell had gone wrong.
        $this->expectException(RuntimeException::class);

        $this->drawIn(self::MAIN, Tile::fromString('05'), SeatNumber::first(), $this->roster(5));
    }

    public function test_tr_45_in_main_every_other_face_is_answered_by_the_drawer(): void
    {
        foreach ([0, 1, 2, 4, 5, 6] as $face) {
            $outcome = $this->drawIn(self::MAIN, Tile::of($face, $face), SeatNumber::fromInt(4), $this->roster(5, 2));

            $this->assertSame(
                [[TridentRuleSet::challengeKey($face), 4], [TridentRuleSet::challengeKey($face), 4]],
                $this->targets($outcome),
            );
        }
    }

    public function test_tr_46_the_double_three_in_main_is_a_tile_with_two_threes(): void
    {
        $outcome = $this->drawIn(self::MAIN, Tile::fromString(self::DOUBLE_THREE), SeatNumber::fromInt(4), $this->roster(5, 2));

        // Two challenges of its face to the seat that answers for a three, which
        // is what every other double does for its own face, and nothing else: no
        // role, no stage change, no override.
        $this->assertSame([['challenge.face.3', 2], ['challenge.face.3', 2]], $this->targets($outcome));
        $this->assertCount(2, $outcome->effects());
        $this->assertNull($outcome->nextStage());
        $this->assertFalse($outcome->isFinished());
        $this->assertNull($outcome->overrideNextSeat());
    }

    public function test_tr_48_the_electing_draw_emits_the_role_and_nothing_else(): void
    {
        $outcome = $this->drawIn(self::ELECTION, Tile::fromString(self::DOUBLE_THREE), SeatNumber::fromInt(4));

        // One effect for the whole stage. The tile that ends the election carries
        // two threes and fires neither of them.
        $this->assertSame(
            [['kind' => 'assign_role', 'seat' => 4, 'role' => 'trident']],
            array_map(static fn (Effect $effect): array => $effect->toArray(), $outcome->effects()),
        );
    }

    public function test_tr_48b_a_draw_and_its_challenges_travel_in_one_outcome(): void
    {
        // The pause between the flip and the challenges is the client's: no
        // outcome and no effect carries a delay, an order or a duration.
        $outcome = $this->drawIn(self::ELECTION, Tile::fromString(self::DOUBLE_THREE));

        $this->assertSame(
            ['effects', 'override_next_seat', 'next_stage', 'pending_choice', 'rule_state_patch', 'finished', 'finish_reason'],
            array_keys($outcome->toArray()),
        );

        foreach ($outcome->effects() as $effect) {
            $this->assertSame([], array_intersect(array_keys($effect->toArray()), ['delay', 'delay_ms', 'duration', 'order']));
        }
    }

    public function test_tr_50_it_emits_only_roles_and_challenges(): void
    {
        $kinds = array_map(
            static fn (Effect $effect): string => $effect->kind(),
            $this->effectsOf($this->fullGame()),
        );

        $emitted = array_values(array_unique($kinds));
        sort($emitted);

        $this->assertSame([EffectKind::ASSIGN_ROLE, EffectKind::CHALLENGE], $emitted);
        $this->assertNotContains(EffectKind::ANNOUNCE, $kinds);
    }

    // ------------------------------------------------------------ the settings

    public function test_tr_51_the_seven_challenge_texts_are_bounded_text_with_a_default(): void
    {
        $spec = $this->ruleSet()->roomConfigSpec();

        for ($face = 0; $face <= TridentRuleSet::MAX_FACE; $face++) {
            $field = $spec->field(TridentRuleSet::challengeKey($face));

            $this->assertSame('text', $field->kind());
            $this->assertSame(80, $field->maxLength());

            // The label names the face, so a screen heading a card with it never
            // has to take the number out of the key itself.
            $this->assertSame("Face {$face}", $field->label());
            $this->assertNotSame('', $field->default());
            $this->assertLessThanOrEqual(80, mb_strlen((string) $field->default()));
            $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', (string) $field->default());
        }
    }

    public function test_tr_52_drawn_tiles_defaults_to_keep_in_the_election_and_remove_in_main(): void
    {
        $resolved = $this->roomConfig()->toArray();

        $this->assertSame('keep', $resolved['drawn_tiles.election']);
        $this->assertSame('remove', $resolved['drawn_tiles.main']);
        $this->assertSame(['keep', 'remove'], $this->ruleSet()->roomConfigSpec()->field('drawn_tiles.main')->options());
    }

    public function test_tr_52_the_board_keeps_its_taken_positions_in_the_election_and_clears_them_in_main(): void
    {
        // The defaults, answered as the projection's own vocabulary: the tiles
        // stay on the table while the trident is being elected and are taken away
        // during the game itself, which is what the author asked for.
        $this->assertSame(
            BoardPresence::TAKEN_STAYS_ON_BOARD,
            $this->ruleSet()->boardPresence($this->gameContext(self::ELECTION)),
        );
        $this->assertSame(
            BoardPresence::TAKEN_LEAVES_BOARD,
            $this->ruleSet()->boardPresence($this->gameContext(self::MAIN)),
        );
    }

    public function test_tr_52_the_table_decides_it_and_both_values_reach_the_projection(): void
    {
        // The setting is read off the key this ruleset declared for the stage in
        // play, so swapping both values swaps both answers. This is the whole of
        // what the control in the lobby is wired to.
        $swapped = RoomConfig::resolve(
            ['drawn_tiles.election' => 'remove', 'drawn_tiles.main' => 'keep'],
            $this->ruleSet()->roomConfigSpec(),
        );

        $this->assertSame(
            BoardPresence::TAKEN_LEAVES_BOARD,
            $this->ruleSet()->boardPresence($this->gameContext(self::ELECTION, config: $swapped)),
        );
        $this->assertSame(
            BoardPresence::TAKEN_STAYS_ON_BOARD,
            $this->ruleSet()->boardPresence($this->gameContext(self::MAIN, config: $swapped)),
        );
    }

    public function test_tr_52_a_value_that_is_not_one_of_the_two_keeps_the_board(): void
    {
        // `RoomConfig::resolve()` already refuses to store a value outside the
        // declared options, so this is the state that only a hand-edited row
        // could be in: the board keeps what it is drawing rather than going
        // blank, because `remove` is the only value that clears it.
        $this->assertSame(
            BoardPresence::TAKEN_STAYS_ON_BOARD,
            $this->ruleSet()->boardPresence($this->gameContext(
                self::MAIN,
                config: RoomConfig::fromArray(['drawn_tiles.main' => 'burn']),
            )),
        );
    }

    public function test_tr_53_rewriting_every_challenge_text_changes_no_draw(): void
    {
        $rewritten = RoomConfig::resolve(
            array_merge(
                array_combine(
                    array_map(static fn (int $face): string => TridentRuleSet::challengeKey($face), range(0, TridentRuleSet::MAX_FACE)),
                    array_fill(0, 7, 'Anything the table likes'),
                ),
                ['drawn_tiles.election' => 'remove', 'drawn_tiles.main' => 'keep'],
            ),
            $this->ruleSet()->roomConfigSpec(),
        );

        foreach ([self::ELECTION, self::MAIN] as $stage) {
            $seats = $stage === self::MAIN ? $this->roster(5, 2) : $this->roster();

            $this->assertEquals(
                $this->drawIn($stage, Tile::fromString('33'), SeatNumber::fromInt(4), $seats)->toArray(),
                $this->drawIn($stage, Tile::fromString('33'), SeatNumber::fromInt(4), $seats, $rewritten)->toArray(),
            );
        }
    }

    // --------------------------------------------------------- nothing is counted

    public function test_tr_54_no_effect_carries_a_quantity(): void
    {
        foreach ($this->effectsOf($this->fullGame()) as $effect) {
            foreach ($effect->toArray() as $key => $value) {
                $this->assertContains($key, ['kind', 'seat', 'role', 'config_key']);
                $this->assertTrue($key === 'seat' || is_string($value));
            }
        }
    }

    public function test_tr_56_a_draw_is_decided_from_its_own_context_alone(): void
    {
        /*
         * The ruleset holds nothing a game can change, so a second instance that
         * has never seen one answers the same draw identically.
         *
         * Asserted as "every property is readonly" and not as "there are no
         * properties": the deployment's seven default phrases live on this
         * object, because the domain may not read a configuration file. They are
         * written once at construction and only ever read, which is the property
         * that mattered — a rule that could remember a previous game is a rule
         * that could decide by one (TR-55, TR-56).
         */
        foreach ((new ReflectionClass(TridentRuleSet::class))->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "TridentRuleSet::\${$property->getName()} is writable, so a game could leave a trace in it.",
            );
        }

        $context = $this->drawContextFor(self::MAIN, Tile::fromString('36'), SeatNumber::fromInt(4), $this->roster(5, 2));

        $this->assertEquals(
            (new TridentRuleSet)->onTileDrawn($context)->toArray(),
            (new TridentRuleSet)->onTileDrawn($context)->toArray(),
        );
    }

    // ------------------------------------------------------------- the arithmetic

    public function test_tr_57_a_full_main_fires_each_key_fourteen_times(): void
    {
        $keys = $this->configKeysOf($this->game()['main']);
        $counted = array_count_values($keys);

        ksort($counted);

        $this->assertCount(98, $keys);
        $this->assertSame(
            array_fill_keys(array_map(static fn (int $face): string => TridentRuleSet::challengeKey($face), range(0, TridentRuleSet::MAX_FACE)), 14),
            $counted,
        );
    }

    public function test_tr_58_the_trident_answers_exactly_fourteen_challenges_in_a_full_main(): void
    {
        $game = $this->game();
        $trident = $game['trident'];

        $answered = array_filter(
            $this->effectsOf($game['main']),
            static fn (Effect $effect): bool => $effect->seat()?->equals($trident) === true,
        );

        $byTheTrident = array_filter(
            $answered,
            static fn (Effect $effect): bool => $effect->configKey() === TridentRuleSet::challengeKey(3),
        );

        $this->assertCount(14, $byTheTrident);
        $this->assertCount(14 + $this->challengesDrawnBy($game['main'], $trident), $answered);
    }

    // ------------------------------------------------------- through the framework
    //
    // Driven through the aggregate: `Game::start()` and `Game::drawTile()` own
    // the status, the cursor, the stage and the pool, and `RuleDriver` adds a
    // literal seed, a frozen clock and a chainable call. Every value object,
    // context and outcome below is the real one.

    public function test_tr_10_exactly_one_seat_acts_at_a_time(): void
    {
        // The cursor is the whole answer to who acts: one seat of the roster,
        // never two, and never none while the game is running.
        $driver = RuleDriver::of($this->ruleSet(), $this->roster(3))->start();

        for ($turn = 0; $turn < 12 && $driver->status() === GameStatus::RUNNING; $turn++) {
            $acting = $driver->cursor();

            $this->assertNotNull($acting);
            $this->assertTrue($driver->seats()->has($acting));
            $this->assertNull($driver->pendingChoice());

            $driver = $driver->draw($driver->firstUntakenPosition());

            $this->assertSame($acting->value(), $driver->history()[$turn]['seat']);
        }

        $this->assertSame(12, $driver->turnNumber());
    }

    public function test_tr_11_a_draw_carries_no_seat_number(): void
    {
        // A draw is a position, the rules and a clock: it is attributed to the
        // current seat the aggregate already holds, so no caller can name the
        // seat it is recorded against.
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName().':'.((string) $parameter->getType()),
            (new ReflectionMethod(Game::class, 'drawTile'))->getParameters(),
        );

        $this->assertSame(
            [
                'position:'.PoolPosition::class,
                'rules:'.RuleSet::class,
                'clock:'.Clock::class,
            ],
            $parameters,
        );
        $this->assertNotContains(SeatNumber::class, array_map(
            static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            (new ReflectionMethod(Game::class, 'drawTile'))->getParameters(),
        ));
    }

    public function test_tr_27_the_framework_applies_the_role_the_election_assigns(): void
    {
        // A whole game through the framework loop with no role seeded by hand:
        // the election emits `assign_role`, the framework writes it onto the
        // roster, and every draw of main reads it back from there (TR-29).
        $driver = RuleDriver::of($this->ruleSet(), $this->roster(3))->start()->playOut();

        $elected = null;

        foreach ($driver->history() as $draw) {
            if ($draw['stage'] === self::ELECTION && $draw['tile'] === self::DOUBLE_THREE) {
                $elected = $draw['seat'];
            }
        }

        $carriers = array_values(array_filter(
            $driver->seats()->seats(),
            static fn (Seat $seat): bool => in_array(TridentRuleSet::ROLE_TRIDENT, $seat->roles(), true),
        ));

        $this->assertNotNull($elected);
        $this->assertCount(1, $carriers);
        $this->assertSame($elected, $carriers[0]->number()->value());

        // And main was playable: 49 draws, ended by its own pool (TR-34, TR-36).
        $this->assertCount(49, array_filter(
            $driver->stagesPlayed(),
            static fn (string $stage): bool => $stage === self::MAIN,
        ));
        $this->assertSame(GameStatus::FINISHED, $driver->status());
        $this->assertSame(FinishReason::POOL_EXHAUSTED, $driver->finishReason());
    }

    // ------------------------------------------------------------------ fixtures

    private function ruleSet(): TridentRuleSet
    {
        return new TridentRuleSet;
    }

    private function roster(int $seats = 5, ?int $trident = null): SeatRoster
    {
        $list = [];

        for ($number = 1; $number <= $seats; $number++) {
            $list[] = Seat::of(
                SeatNumber::fromInt($number),
                Nickname::fromString("Player {$number}"),
                $number === $trident ? [TridentRuleSet::ROLE_TRIDENT] : [],
            );
        }

        return SeatRoster::fromSeats($list);
    }

    private function roomConfig(): RoomConfig
    {
        return RoomConfig::resolve([], $this->ruleSet()->roomConfigSpec());
    }

    private function gameContext(string $stage, ?SeatRoster $seats = null, ?RoomConfig $config = null): GameContext
    {
        return GameContext::of(
            StageId::fromString($stage),
            $seats ?? $this->roster(),
            null,
            DrawLog::empty(),
            0,
            RuleState::initial(TridentRuleSet::STATE_VERSION),
            $config ?? $this->roomConfig(),
        );
    }

    private function pool(string $stage): TilePool
    {
        return TilePool::fromDeck(TileDeck::standard(), Seed::fromString(self::SEED), $stage);
    }

    private function positionOf(TilePool $pool, Tile $tile): PoolPosition
    {
        foreach ($pool->tiles() as $index => $held) {
            if ($held->equals($tile)) {
                return PoolPosition::fromInt($index + 1);
            }
        }

        self::fail("The pool does not hold {$tile->value()}.");
    }

    private function emptiedExcept(TilePool $pool, PoolPosition $keep): TilePool
    {
        for ($position = 1; $position <= $pool->count(); $position++) {
            if ($position !== $keep->value()) {
                $pool = $pool->take(PoolPosition::fromInt($position), SeatNumber::first());
            }
        }

        return $pool;
    }

    /** The draw of one tile from the stage's real pool, at the position it sits in. */
    private function drawContextFor(
        string $stage,
        Tile $tile,
        SeatNumber $seat,
        SeatRoster $seats,
        ?RoomConfig $roomConfig = null,
    ): DrawContext {
        $pool = $this->pool($stage);

        return $this->drawContext($stage, $tile, $seat, $seats, $pool, $this->positionOf($pool, $tile), 1, $roomConfig);
    }

    private function drawContext(
        string $stage,
        Tile $tile,
        SeatNumber $seat,
        SeatRoster $seats,
        TilePool $poolBefore,
        PoolPosition $position,
        int $turnNumber,
        ?RoomConfig $roomConfig = null,
    ): DrawContext {
        return DrawContext::of(
            $seat,
            $position,
            $tile,
            $seats,
            DrawLog::empty(),
            $poolBefore,
            $turnNumber,
            StageId::fromString($stage),
            RuleState::initial(TridentRuleSet::STATE_VERSION),
            $roomConfig ?? $this->roomConfig(),
        );
    }

    private function drawIn(
        string $stage,
        Tile $tile,
        ?SeatNumber $seat = null,
        ?SeatRoster $seats = null,
        ?RoomConfig $roomConfig = null,
    ): Outcome {
        return $this->ruleSet()->onTileDrawn($this->drawContextFor(
            $stage,
            $tile,
            $seat ?? SeatNumber::first(),
            // The election needs no trident and main cannot be played without one.
            $seats ?? ($stage === self::MAIN ? $this->roster(5, 2) : $this->roster()),
            $roomConfig,
        ));
    }

    /**
     * One stage, played position by position from its own seeded pool, with the
     * ring rotating and the ruleset's overrides applied exactly as the aggregate
     * would apply them.
     *
     * @return list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>
     */
    private function play(string $stage, SeatRoster $seats, ?SeatNumber $opener = null): array
    {
        $ruleSet = $this->ruleSet();
        $pool = $this->pool($stage);
        $log = DrawLog::empty();
        $seat = $opener ?? SeatNumber::first();
        $turns = [];

        for ($number = 1; $number <= $pool->count(); $number++) {
            $position = PoolPosition::fromInt($number);
            $tile = $pool->at($position);

            $outcome = $ruleSet->onTileDrawn(DrawContext::of(
                $seat,
                $position,
                $tile,
                $seats,
                $log,
                $pool,
                $number,
                StageId::fromString($stage),
                RuleState::initial(TridentRuleSet::STATE_VERSION),
                $this->roomConfig(),
            ));

            $turns[] = ['seat' => $seat, 'position' => $position, 'tile' => $tile, 'outcome' => $outcome];

            $pool = $pool->take($position, $seat);
            $log = $log->append(Draw::of($stage, $seat, $position, $tile));

            if ($outcome->isFinished() || $outcome->nextStage() !== null) {
                break;
            }

            $seat = $outcome->overrideNextSeat() ?? SeatRing::next($seat, $seats->count());
        }

        return $turns;
    }

    /**
     * A whole game: the election, the role it assigns applied to the roster, and
     * main opened by the seat the electing draw named.
     *
     * @return array{election: list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>, main: list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>, trident: SeatNumber}
     */
    private function game(int $seatCount = 5): array
    {
        $election = $this->play(self::ELECTION, $this->roster($seatCount));
        $electing = $this->lastOf($election);

        // The role main is played with is the one the election emitted, applied
        // the way the framework applies it (TR-27), and never a seeded fixture.
        $seats = $this->withRolesApplied($this->roster($seatCount), $this->effectsOf($election));

        return [
            'election' => $election,
            'main' => $this->play(self::MAIN, $seats, $electing['outcome']->overrideNextSeat()),
            'trident' => $this->tridentOf($seats),
        ];
    }

    /**
     * Every `assign_role` of a stage, applied to the roster in the order it was
     * emitted: what the framework does with the effect (TR-27).
     *
     * @param  list<Effect>  $effects
     */
    private function withRolesApplied(SeatRoster $seats, array $effects): SeatRoster
    {
        foreach ($effects as $effect) {
            $seat = $effect->seat();
            $role = $effect->role();

            if ($effect->kind() === EffectKind::ASSIGN_ROLE && $seat !== null && $role !== null) {
                $seats = $seats->assignRole($seat, $role);
            }
        }

        return $seats;
    }

    private function tridentOf(SeatRoster $seats): SeatNumber
    {
        foreach ($seats->seats() as $seat) {
            if (in_array(TridentRuleSet::ROLE_TRIDENT, $seat->roles(), true)) {
                return $seat->number();
            }
        }

        self::fail('The election assigned no trident.');
    }

    /**
     * @return list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>
     */
    private function fullGame(int $seatCount = 5): array
    {
        $game = $this->game($seatCount);

        return [...$game['election'], ...$game['main']];
    }

    /**
     * @param  list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>  $turns
     * @return array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}
     */
    private function lastOf(array $turns): array
    {
        return $turns[count($turns) - 1];
    }

    /**
     * @param  list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>  $turns
     * @return list<Effect>
     */
    private function effectsOf(array $turns): array
    {
        $effects = [];

        foreach ($turns as $turn) {
            $effects = [...$effects, ...$turn['outcome']->effects()];
        }

        return $effects;
    }

    /**
     * @return list<string>
     */
    private function configKeys(Outcome $outcome): array
    {
        $keys = [];

        foreach ($outcome->effects() as $effect) {
            if ($effect->configKey() !== null) {
                $keys[] = $effect->configKey();
            }
        }

        return $keys;
    }

    /**
     * @param  list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>  $turns
     * @return list<string>
     */
    private function configKeysOf(array $turns): array
    {
        $keys = [];

        foreach ($turns as $turn) {
            $keys = [...$keys, ...$this->configKeys($turn['outcome'])];
        }

        return $keys;
    }

    /**
     * Each challenge as the pair that matters: the key it carries and the seat it
     * is addressed to.
     *
     * @return list<array{0: string, 1: int|null}>
     */
    private function targets(Outcome $outcome): array
    {
        $targets = [];

        foreach ($outcome->effects() as $effect) {
            if ($effect->kind() === EffectKind::CHALLENGE) {
                $targets[] = [(string) $effect->configKey(), $effect->seat()?->value()];
            }
        }

        return $targets;
    }

    /**
     * How many challenges of a face other than three landed on one seat because
     * it was the seat that drew.
     *
     * @param  list<array{seat: SeatNumber, position: PoolPosition, tile: Tile, outcome: Outcome}>  $turns
     */
    private function challengesDrawnBy(array $turns, SeatNumber $seat): int
    {
        $count = 0;

        foreach ($turns as $turn) {
            if (! $turn['seat']->equals($seat)) {
                continue;
            }

            foreach ($this->targets($turn['outcome']) as [$key, $target]) {
                if ($key !== TridentRuleSet::challengeKey(3) && $target === $seat->value()) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function faces(TileDeck|TilePool $tiles): array
    {
        return array_map(static fn (Tile $tile): string => $tile->value(), $tiles->tiles());
    }
}
