<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\Outcome;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * Everything a rule may ask the framework to do, and nothing else. Its bytes are
 * pinned here because two clients and a jsonb column read them.
 */
final class OutcomeTest extends TestCase
{
    public function test_an_outcome_that_asks_for_nothing_still_answers_every_question(): void
    {
        $this->assertSame(
            [
                'effects' => [],
                'override_next_seat' => null,
                'next_stage' => null,
                'pending_choice' => null,
                'rule_state_patch' => [],
                'finished' => false,
                'finish_reason' => null,
            ],
            Outcome::empty()->toArray(),
        );
    }

    public function test_effects_keep_the_order_the_rule_added_them_in(): void
    {
        // A role is assigned before the challenges of the tile that assigned it,
        // and the left face is announced before the right one.
        $outcome = Outcome::of(Effect::assignRole(SeatNumber::fromInt(2), 'trident'))
            ->withEffect(Effect::challenge(SeatNumber::fromInt(2), 'challenge.face.3'))
            ->withEffect(Effect::challenge(SeatNumber::fromInt(2), 'challenge.face.3'));

        $this->assertSame(
            ['assign_role', 'challenge', 'challenge'],
            array_map(static fn (Effect $effect): string => $effect->kind(), $outcome->effects()),
        );
    }

    public function test_it_carries_the_whole_vocabulary_on_the_wire(): void
    {
        $outcome = Outcome::of(Effect::challenge(SeatNumber::fromInt(1), 'challenge.face.3'))
            ->withNextSeat(SeatNumber::first())
            ->withNextStage(StageId::fromString('main'))
            ->withRuleStatePatch(['elected' => true]);

        $this->assertSame(
            [
                'effects' => [['kind' => 'challenge', 'seat' => 1, 'config_key' => 'challenge.face.3']],
                'override_next_seat' => 1,
                'next_stage' => 'main',
                'pending_choice' => null,
                'rule_state_patch' => ['elected' => true],
                'finished' => false,
                'finish_reason' => null,
            ],
            $outcome->toArray(),
        );
        $this->assertSame($outcome->toArray(), json_decode((string) json_encode($outcome), true));
    }

    public function test_it_is_immutable(): void
    {
        $outcome = Outcome::empty();

        $outcome->withEffect(Effect::challenge(null, 'challenge.face.0'))
            ->withNextSeat(SeatNumber::first())
            ->withNextStage(StageId::fromString('main'))
            ->withRuleStatePatch(['elected' => true]);

        $this->assertSame([], $outcome->effects());
        $this->assertNull($outcome->overrideNextSeat());
        $this->assertNull($outcome->nextStage());
        $this->assertSame([], $outcome->ruleStatePatch());
    }

    public function test_the_turn_order_is_expressed_only_here(): void
    {
        // There is no skip-turn effect: an effect that also moved the cursor would
        // be a second source of truth about who plays next.
        $outcome = Outcome::empty()->withNextSeat(SeatNumber::fromInt(4));

        $this->assertSame(4, $outcome->overrideNextSeat()?->value());
    }

    public function test_a_stage_transition_is_a_field_and_not_a_method_of_the_seam(): void
    {
        $outcome = Outcome::empty()->withNextStage(StageId::fromString('main'));

        $this->assertSame('main', $outcome->nextStage()?->value());
    }

    public function test_a_game_never_finishes_without_a_reason(): void
    {
        $outcome = Outcome::empty()->finishedBecause(FinishReason::POOL_EXHAUSTED);

        $this->assertTrue($outcome->isFinished());
        $this->assertSame('pool_exhausted', $outcome->finishReason());
        $this->assertFalse(Outcome::empty()->isFinished());
        $this->assertNull(Outcome::empty()->finishReason());
    }

    public function test_a_reason_outside_the_vocabulary_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Outcome::empty()->finishedBecause('everyone_left');
    }

    public function test_a_finished_game_does_not_also_move_to_another_stage(): void
    {
        foreach (
            [
                fn (): Outcome => Outcome::empty()
                    ->withNextStage(StageId::fromString('main'))
                    ->finishedBecause(FinishReason::POOL_EXHAUSTED),
                fn (): Outcome => Outcome::empty()
                    ->finishedBecause(FinishReason::POOL_EXHAUSTED)
                    ->withNextStage(StageId::fromString('main')),
            ] as $contradiction
        ) {
            try {
                $contradiction();
                $this->fail('A finished game should not move to another stage.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_rule_cannot_patch_the_state_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Outcome::empty()->withRuleStatePatch(['_v' => 2]);
    }

    public function test_a_second_patch_adds_to_the_first(): void
    {
        $outcome = Outcome::empty()
            ->withRuleStatePatch(['elected' => true])
            ->withRuleStatePatch(['round' => 2]);

        $this->assertSame(['elected' => true, 'round' => 2], $outcome->ruleStatePatch());
    }
}
