<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Exceptions\UnknownRuleSetException;
use Src\Game\Domain\Rules\BoardPresence;
use Src\Game\Domain\Rules\ChoiceContext;
use Src\Game\Domain\Rules\DrawContext;
use Src\Game\Domain\Rules\GameContext;
use Src\Game\Domain\Rules\Outcome;
use Src\Game\Domain\Rules\RoomConfigSpec;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\RuleSetResolver;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\Rules\StageSequence;
use Src\Game\Domain\Rules\Visibility;
use Src\Game\Domain\ValueObjects\TileDeck;

/**
 * A game in flight is pinned to the ruleset it was born with.
 */
final class RuleSetResolverTest extends TestCase
{
    /** A ruleset that answers every method and implements no game rule. */
    private function ruleSet(string $id, int $stateVersion = 1): RuleSet
    {
        return new class($id, $stateVersion) implements RuleSet
        {
            public function __construct(private readonly string $id, private readonly int $stateVersion) {}

            public function id(): string
            {
                return $this->id;
            }

            public function stateVersion(): int
            {
                return $this->stateVersion;
            }

            public function stages(): StageSequence
            {
                return StageSequence::of(StageId::fromString('only'), 'Only');
            }

            public function deck(GameContext $context): TileDeck
            {
                return TileDeck::standard();
            }

            public function visibility(GameContext $context): string
            {
                return Visibility::FACES_HIDDEN_UNTIL_TAKEN;
            }

            public function boardPresence(GameContext $context): string
            {
                return BoardPresence::TAKEN_STAYS_ON_BOARD;
            }

            public function roomConfigSpec(): RoomConfigSpec
            {
                return RoomConfigSpec::of();
            }

            public function onGameStarted(GameContext $context): Outcome
            {
                return Outcome::empty();
            }

            public function onChoiceMade(ChoiceContext $context): Outcome
            {
                return Outcome::empty();
            }

            public function onTileDrawn(DrawContext $context): Outcome
            {
                return Outcome::empty();
            }
        };
    }

    public function test_it_hands_back_the_ruleset_a_game_was_born_with(): void
    {
        $resolver = new RuleSetResolver([$this->ruleSet('trident.v1'), $this->ruleSet('house.v3')]);

        $this->assertSame('trident.v1', $resolver->resolve('trident.v1')->id());
        $this->assertSame('house.v3', $resolver->resolve('house.v3')->id());
    }

    public function test_the_same_id_always_resolves_to_the_same_instance(): void
    {
        $ruleSet = $this->ruleSet('trident.v1');
        $resolver = new RuleSetResolver([$ruleSet]);

        $this->assertSame($ruleSet, $resolver->resolve('trident.v1'));
    }

    public function test_it_knows_what_it_holds(): void
    {
        $resolver = new RuleSetResolver([$this->ruleSet('trident.v1')]);

        $this->assertTrue($resolver->has('trident.v1'));
        $this->assertFalse($resolver->has('trident.v2'));
        $this->assertSame(['trident.v1'], $resolver->ids());
    }

    public function test_an_id_it_does_not_hold_is_an_incident_and_never_a_silent_default(): void
    {
        // Falling back to the deployment's default would change the meaning of a
        // game already on a table.
        $resolver = new RuleSetResolver([$this->ruleSet('trident.v1')]);

        $this->expectException(UnknownRuleSetException::class);

        $resolver->resolve('trident.v2');
    }

    public function test_a_resolver_with_nothing_in_it_resolves_nothing(): void
    {
        $this->expectException(UnknownRuleSetException::class);

        (new RuleSetResolver([]))->resolve('trident.v1');
    }

    public function test_two_rulesets_cannot_claim_the_same_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RuleSetResolver([$this->ruleSet('trident.v1'), $this->ruleSet('trident.v1', 2)]);
    }
}
