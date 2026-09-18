<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Doubles;

use RuntimeException;
use Src\Game\Domain\Rules\BoardPresence;
use Src\Game\Domain\Rules\ChoiceContext;
use Src\Game\Domain\Rules\DrawContext;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\GameContext;
use Src\Game\Domain\Rules\Outcome;
use Src\Game\Domain\Rules\RoomConfigField;
use Src\Game\Domain\Rules\RoomConfigSpec;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\Rules\StageSequence;
use Src\Game\Domain\Rules\Visibility;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TileDeck;

/**
 * The next deployment of `HostileRuleSet`: **the same `id()`** and one state
 * version higher.
 *
 * It is the eighth stress of documentation/conventions/rule-set-seam.md and the
 * only thing that ever executes `FinishReason::RULESET_UPGRADED`. A game is
 * pinned to a ruleset id and not to a version (`games.rule_set_id`), so a table
 * that was mid-game when this shipped is handed a `games.rule_state` its
 * predecessor wrote. This one cannot migrate that blob, so it ends the game
 * rather than reading a state it believes it wrote itself.
 *
 * Sharing the id with `HostileRuleSet` is the point and not an oversight: the two
 * are never registered together, and `RuleSetResolver` refuses the pair, which is
 * what makes an upgrade a replacement.
 */
final class HostileRuleSetV2 implements RuleSet
{
    public const ID = HostileRuleSet::ID;

    public const STATE_VERSION = HostileRuleSet::STATE_VERSION + 1;

    public const STAGE_ALPHA = HostileRuleSet::STAGE_ALPHA;

    public function id(): string
    {
        return self::ID;
    }

    public function stateVersion(): int
    {
        return self::STATE_VERSION;
    }

    public function stages(): StageSequence
    {
        return StageSequence::of(self::alpha(), 'Alpha');
    }

    public function deck(GameContext $context): TileDeck
    {
        return TileDeck::of(Tile::of(0, 1), Tile::of(1, 2), Tile::of(2, 3), Tile::of(3, 4));
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
        return RoomConfigSpec::of(
            RoomConfigField::text(
                HostileRuleSet::CONFIG_BANNER,
                'Banner',
                'Hostile banner',
                HostileRuleSet::BANNER_MAX_LENGTH,
            ),
        );
    }

    public function onGameStarted(GameContext $context): Outcome
    {
        return Outcome::empty()->withNextSeat(SeatNumber::first());
    }

    /**
     * The version guard, which is two lines and the reason `RuleState` carries
     * `_v` at all: a state this version did not write is ended, not guessed at.
     */
    public function onTileDrawn(DrawContext $context): Outcome
    {
        if (! $context->state()->isAtVersion(self::STATE_VERSION)) {
            return Outcome::empty()->finishedBecause(FinishReason::RULESET_UPGRADED);
        }

        return Outcome::of(Effect::challenge($context->seat(), HostileRuleSet::CONFIG_BANNER));
    }

    public function onChoiceMade(ChoiceContext $context): Outcome
    {
        throw new RuntimeException(self::ID.' version '.self::STATE_VERSION.' never asks a seat to choose.');
    }

    private static function alpha(): StageId
    {
        return StageId::fromString(self::STAGE_ALPHA);
    }
}
