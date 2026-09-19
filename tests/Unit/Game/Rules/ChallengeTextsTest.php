<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\Trident\ChallengeTexts;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;

/**
 * The seven phrases a table starts with.
 *
 * They are a per-deployment value and not a rule: rewriting all seven changes no
 * draw (TR-53). What this class has to guarantee is only that whatever a
 * deployment sets can actually be painted across a television, because nothing
 * downstream validates it — `RoomConfig` validates what the *table* writes, and
 * a default is not written by a table.
 */
final class ChallengeTextsTest extends TestCase
{
    public function test_it_ships_one_phrase_per_face(): void
    {
        $texts = ChallengeTexts::shipped();

        $this->assertCount(TridentRuleSet::MAX_FACE + 1, $texts->all());

        for ($face = 0; $face <= TridentRuleSet::MAX_FACE; $face++) {
            $this->assertNotSame('', $texts->of($face), 'A declared default is never empty (TR-51).');
        }
    }

    public function test_a_deployment_can_replace_any_of_them(): void
    {
        $texts = ChallengeTexts::fromOverrides([3 => 'The trident drinks.']);

        $this->assertSame('The trident drinks.', $texts->of(3));
        $this->assertSame(ChallengeTexts::shipped()->of(4), $texts->of(4));
    }

    public function test_a_deployment_can_replace_all_of_them(): void
    {
        $written = [];

        for ($face = 0; $face <= TridentRuleSet::MAX_FACE; $face++) {
            $written[$face] = "Do the thing for {$face}.";
        }

        $this->assertSame($written, ChallengeTexts::fromOverrides($written)->all());
    }

    public function test_a_variable_nobody_set_is_not_an_override(): void
    {
        // Absent, null and blank all mean the same thing: nobody wrote this one.
        // Treating them as an override would ship an empty box, which TR-51
        // forbids and which reads on a television as a challenge that says
        // nothing.
        $shipped = ChallengeTexts::shipped();

        foreach ([[], [0 => null], [0 => ''], [0 => '   '], [0 => 42]] as $overrides) {
            $this->assertSame($shipped->of(0), ChallengeTexts::fromOverrides($overrides)->of(0));
        }
    }

    public function test_it_trims_what_a_deployment_wrote(): void
    {
        $this->assertSame('Drink.', ChallengeTexts::fromOverrides([1 => '  Drink.  '])->of(1));
    }

    public function test_it_refuses_a_phrase_a_television_cannot_hold(): void
    {
        // Refused at boot rather than in front of a room. Nothing else would have
        // caught it: a declared default is checked for emptiness and never for
        // length, so an eighty-first character would have reached the screen.
        $this->expectException(InvalidArgumentException::class);

        ChallengeTexts::fromOverrides([2 => str_repeat('a', TridentRuleSet::CHALLENGE_MAX_LENGTH + 1)]);
    }

    public function test_it_accepts_a_phrase_of_exactly_the_maximum(): void
    {
        $longest = str_repeat('a', TridentRuleSet::CHALLENGE_MAX_LENGTH);

        $this->assertSame($longest, ChallengeTexts::fromOverrides([2 => $longest])->of(2));
    }

    public function test_it_refuses_a_line_break(): void
    {
        // The card is one block of text at the size of a room. A newline in it
        // breaks the layout, and an environment file is exactly where one gets in.
        $this->expectException(InvalidArgumentException::class);

        ChallengeTexts::fromOverrides([5 => "Drink.\nThen drink again."]);
    }

    public function test_it_counts_characters_and_not_bytes(): void
    {
        $accented = str_repeat('á', TridentRuleSet::CHALLENGE_MAX_LENGTH);

        $this->assertSame($accented, ChallengeTexts::fromOverrides([6 => $accented])->of(6));
    }

    public function test_the_ruleset_declares_whatever_it_was_given(): void
    {
        $ruleSet = new TridentRuleSet(ChallengeTexts::fromOverrides([0 => 'Face zero drinks.']));

        $this->assertSame(
            'Face zero drinks.',
            $ruleSet->roomConfigSpec()->field(TridentRuleSet::challengeKey(0))->default(),
        );
    }

    public function test_a_ruleset_built_with_nothing_still_declares_seven_defaults(): void
    {
        // Every test of a rule constructs the ruleset bare, and a rule is what
        // they are testing: the phrases must not become a required argument.
        $spec = (new TridentRuleSet)->roomConfigSpec();

        for ($face = 0; $face <= TridentRuleSet::MAX_FACE; $face++) {
            $this->assertNotSame('', $spec->field(TridentRuleSet::challengeKey($face))->default());
        }
    }

    public function test_the_phrases_change_no_draw(): void
    {
        // TR-53 stated as an assertion: two rulesets whose seven boxes read
        // completely differently answer the same draw with the same keys, the
        // same recipients and the same order.
        $bare = new TridentRuleSet;
        $rewritten = new TridentRuleSet(ChallengeTexts::fromOverrides(
            array_fill(0, TridentRuleSet::MAX_FACE + 1, 'Something else entirely.'),
        ));

        $this->assertSame(
            array_keys($bare->roomConfigSpec()->toArray()),
            array_keys($rewritten->roomConfigSpec()->toArray()),
        );
    }
}
