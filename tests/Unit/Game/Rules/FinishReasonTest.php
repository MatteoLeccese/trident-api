<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\FinishReason;

final class FinishReasonTest extends TestCase
{
    public function test_it_declares_every_way_a_game_can_end(): void
    {
        $this->assertSame(
            [
                'pool_exhausted',
                'ruleset_upgraded',
                'rules_ended_game',
                'idle_timeout',
                'replaced_by_rematch',
            ],
            FinishReason::ALL,
        );
    }

    public function test_only_some_of_them_are_a_rules_claim(): void
    {
        // The split is the point. A rule can know that a pool ran out; no rule is
        // in a position to know that the table went home or asked for another
        // game, and a ruleset that said so would be inventing a fact about a room
        // it cannot see.
        $this->assertSame(
            ['pool_exhausted', 'ruleset_upgraded', 'rules_ended_game'],
            FinishReason::FROM_RULES,
        );

        foreach (FinishReason::FROM_RULES as $reason) {
            $this->assertTrue(FinishReason::isFromRules($reason));
            $this->assertTrue(FinishReason::isValid($reason), 'Every rules reason is also a reason.');
        }

        $this->assertFalse(FinishReason::isFromRules(FinishReason::IDLE_TIMEOUT));
        $this->assertFalse(FinishReason::isFromRules(FinishReason::REPLACED_BY_REMATCH));
    }

    public function test_the_two_ways_of_abandoning_a_game_are_told_apart(): void
    {
        // A rematch and a walkout both land on `abandoned`, and nothing else
        // separates them: without two reasons, a room that kept playing all night
        // counts exactly like a room that left after one game.
        $this->assertNotSame(FinishReason::IDLE_TIMEOUT, FinishReason::REPLACED_BY_REMATCH);
        $this->assertTrue(FinishReason::isValid(FinishReason::IDLE_TIMEOUT));
        $this->assertTrue(FinishReason::isValid(FinishReason::REPLACED_BY_REMATCH));
    }

    public function test_a_ruleset_can_end_a_game_without_borrowing_a_reason_that_is_false(): void
    {
        // The first two are specific claims. A ruleset that refuses to play a
        // table would have to lie with one of them, so the vocabulary carries the
        // generic reason as well. No production ruleset emits it.
        $this->assertTrue(FinishReason::isFromRules(FinishReason::RULES_ENDED_GAME));
    }

    public function test_it_recognises_its_own_values_and_nothing_else(): void
    {
        foreach (FinishReason::ALL as $reason) {
            $this->assertTrue(FinishReason::isValid($reason));
        }

        // A status is not a reason: `abandoned` says a game ended and this class
        // says why, and collapsing the two would lose the distinction the whole
        // class exists to keep.
        $this->assertFalse(FinishReason::isValid('abandoned'));
        $this->assertFalse(FinishReason::isValid(''));
        $this->assertFalse(FinishReason::isFromRules(''));
    }
}
