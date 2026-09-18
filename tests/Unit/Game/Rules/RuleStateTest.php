<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\RuleState;

/**
 * The opaque blob a ruleset keeps between turns. It always carries its version:
 * evolving classes over opaque jsonb is a classic path to silent corruption, and
 * this is the two-line guard against it.
 */
final class RuleStateTest extends TestCase
{
    public function test_a_fresh_state_carries_nothing_but_its_version(): void
    {
        $this->assertSame(['_v' => 1], RuleState::initial(1)->toArray());
    }

    public function test_the_version_key_is_the_one_key_the_framework_owns(): void
    {
        $this->assertSame('_v', RuleState::VERSION_KEY);
    }

    public function test_it_reads_back_what_was_stored(): void
    {
        $state = RuleState::fromArray(['_v' => 2, 'seen' => ['33'], 'round' => 3]);

        $this->assertSame(2, $state->version());
        $this->assertTrue($state->isAtVersion(2));
        $this->assertFalse($state->isAtVersion(1));
        $this->assertSame(['33'], $state->get('seen'));
        $this->assertSame(3, $state->get('round'));
        $this->assertSame(['_v' => 2, 'seen' => ['33'], 'round' => 3], $state->toArray());
    }

    public function test_it_does_not_read_a_key_it_was_never_given(): void
    {
        $state = RuleState::initial(1);

        $this->assertFalse($state->has('round'));
        $this->assertNull($state->get('round'));
        $this->assertSame('fallback', $state->get('round', 'fallback'));
    }

    public function test_a_stored_blob_with_no_version_is_refused(): void
    {
        // The column is only ever written by toArray(), which always stamps the
        // version, so a blob without one is corruption and must not be mistaken
        // for a state a ruleset can act on.
        foreach ([[], ['round' => 3], ['_v' => '1'], ['_v' => null]] as $stored) {
            try {
                RuleState::fromArray($stored);
                $this->fail('A stored state with no usable version should be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_version_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuleState::initial(0);
    }

    public function test_a_patch_overwrites_the_keys_it_names_and_leaves_the_rest(): void
    {
        $state = RuleState::fromArray(['_v' => 1, 'round' => 1, 'seen' => ['33']])
            ->with(['round' => 2]);

        $this->assertSame(['_v' => 1, 'round' => 2, 'seen' => ['33']], $state->toArray());
    }

    public function test_patching_leaves_the_state_it_came_from_alone(): void
    {
        $state = RuleState::initial(1);

        $state->with(['round' => 2]);

        $this->assertFalse($state->has('round'));
    }

    public function test_a_patch_cannot_move_the_version(): void
    {
        // The framework stamps the version from RuleSet::stateVersion(); a rule
        // that could patch it would be able to claim it had migrated itself.
        $this->expectException(InvalidArgumentException::class);

        RuleState::initial(1)->with(['_v' => 2]);
    }

    public function test_the_version_of_a_state_is_the_one_that_wrote_it(): void
    {
        // Nothing restamps a stored state: no member of Outcome carries a
        // migrated one, so a version gap has exactly one outcome, which is
        // FinishReason::RULESET_UPGRADED. A framework that moved the version on
        // every write would silently upgrade a state nobody migrated, which is
        // the failure `_v` exists to catch.
        $state = RuleState::fromArray(['_v' => 1, 'round' => 3])->with(['round' => 4]);

        $this->assertSame(['_v' => 1, 'round' => 4], $state->toArray());
        $this->assertFalse(method_exists(RuleState::class, 'migratedTo'));
    }

    public function test_the_version_is_the_first_key_of_the_blob(): void
    {
        $this->assertSame('_v', array_key_first(RuleState::initial(1)->with(['round' => 1])->toArray()));
    }
}
