<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Doubles;

use Src\Game\Domain\Rules\ChoiceContext;
use Src\Game\Domain\Rules\DrawContext;
use Src\Game\Domain\Rules\GameContext;
use Src\Game\Domain\Rules\Outcome;
use Src\Game\Domain\Rules\RoomConfigSpec;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\StageSequence;
use Src\Game\Domain\ValueObjects\TileDeck;

/**
 * A production ruleset with a counter on it: every decision is still the one it
 * wraps, and what this class adds is a record of how many times the aggregate
 * asked.
 *
 * It exists for the assertions that are about **when** the seam is consulted.
 * The aggregate's own refusals — a position outside the pool, a position already
 * taken — land before the ruleset is asked, and a ruleset that saw a draw the
 * aggregate then refused would have moved its own state for a turn that never
 * happened.
 */
final class CountingRuleSet implements RuleSet
{
    public int $tilesDrawn = 0;

    public function __construct(private readonly RuleSet $inner) {}

    public function id(): string
    {
        return $this->inner->id();
    }

    public function stateVersion(): int
    {
        return $this->inner->stateVersion();
    }

    public function stages(): StageSequence
    {
        return $this->inner->stages();
    }

    public function deck(GameContext $context): TileDeck
    {
        return $this->inner->deck($context);
    }

    public function visibility(GameContext $context): string
    {
        return $this->inner->visibility($context);
    }

    public function boardPresence(GameContext $context): string
    {
        return $this->inner->boardPresence($context);
    }

    public function roomConfigSpec(): RoomConfigSpec
    {
        return $this->inner->roomConfigSpec();
    }

    public function onGameStarted(GameContext $context): Outcome
    {
        return $this->inner->onGameStarted($context);
    }

    public function onTileDrawn(DrawContext $context): Outcome
    {
        $this->tilesDrawn++;

        return $this->inner->onTileDrawn($context);
    }

    public function onChoiceMade(ChoiceContext $context): Outcome
    {
        return $this->inner->onChoiceMade($context);
    }
}
