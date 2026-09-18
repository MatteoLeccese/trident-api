<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\FinishReason;

final class FinishReasonTest extends TestCase
{
    public function test_it_declares_the_reasons_a_ruleset_may_give(): void
    {
        $this->assertSame(['pool_exhausted', 'ruleset_upgraded', 'rules_ended_game'], FinishReason::ALL);
    }

    public function test_a_ruleset_can_end_a_game_without_borrowing_a_reason_that_is_false(): void
    {
        // The first two are specific claims. A ruleset that refuses to play a
        // table would have to lie with one of them, so the vocabulary carries the
        // generic reason as well. No production ruleset emits it.
        $this->assertTrue(FinishReason::isValid(FinishReason::RULES_ENDED_GAME));
    }

    public function test_it_recognises_its_own_values_and_nothing_else(): void
    {
        foreach (FinishReason::ALL as $reason) {
            $this->assertTrue(FinishReason::isValid($reason));
        }

        // Expiry through inactivity lands in `abandoned`, which is terminal for
        // the framework and never consults the rules (TR-35).
        $this->assertFalse(FinishReason::isValid('abandoned'));
        $this->assertFalse(FinishReason::isValid(''));
    }
}
