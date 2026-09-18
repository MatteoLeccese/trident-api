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
use Src\Game\Domain\Rules\PendingChoice;
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
 * **Not a game. A load test for the seam.**
 *
 * `trident.v1` and the `RuleSet` interface were designed together, which is the
 * condition under which an interface quietly bakes in the assumptions of its one
 * caller: two stages that only move forward, one fixed 49-tile deck, one
 * visibility, no rule state, no branch on a setting, and a turn that never waits
 * for anybody. This class walks the other edges, and it has to actually run.
 *
 * documentation/conventions/rule-set-seam.md names the eight stresses. This
 * ruleset carries seven of them; the eighth, a ruleset reading a state its
 * previous version wrote, is `HostileRuleSetV2`.
 *
 *  1. `alpha` stops the game mid-turn on a `PendingChoice` and the answer picks
 *     the next stage.
 *  2. `beta` is `faces_open`, so the projection changes shape by stage.
 *  3. `beta`'s deck is four tiles.
 *  4. `alpha` → `gamma` → `beta` → `alpha`: two of the three transitions point
 *     backwards through the declaration order.
 *  5. `onGameStarted` ends a game of zero draws at a table it will not play.
 *  6. every `beta` draw hands the turn back to the seat that just took it.
 *  7. `deck()` and the end of `beta` branch on values the table wrote.
 *
 * Nothing here is a rule of the Trident and nothing here is content: the strings
 * are ids, and the one `challenge` it emits carries a key of its own space.
 */
final class HostileRuleSet implements RuleSet
{
    public const ID = 'hostile.v1';

    public const STATE_VERSION = 1;

    public const STAGE_ALPHA = 'alpha';

    public const STAGE_BETA = 'beta';

    public const STAGE_GAMMA = 'gamma';

    /** The question `alpha` parks its first turn on, and the two answers. */
    public const PROMPT_NEXT_STAGE = 'hostile.which_stage_next';

    public const OPTION_BETA = 'hostile.stage.beta';

    public const OPTION_GAMMA = 'hostile.stage.gamma';

    public const CONFIG_GAMMA_DECK = 'hostile.gamma_deck';

    public const CONFIG_BANNER = 'hostile.banner';

    public const CONFIG_LOOP = 'hostile.loop';

    public const GAMMA_DECK_SHORT = 'short';

    public const GAMMA_DECK_LONG = 'long';

    /** The rule state key `gamma` writes, which `trident.v1` has no equivalent of. */
    public const STATE_LAST_GAMMA_TILE = 'last_gamma_tile';

    public const STATE_CHOSEN_STAGE = 'chosen_stage';

    /** A table bigger than this is one this variant refuses to play. */
    public const MAX_SEATS = 5;

    public const BANNER_MAX_LENGTH = 20;

    public function id(): string
    {
        return self::ID;
    }

    public function stateVersion(): int
    {
        return self::STATE_VERSION;
    }

    /** Declaration order, which is the order `nextStage` is free to ignore. */
    public function stages(): StageSequence
    {
        return StageSequence::of(self::alpha(), 'Alpha')
            ->then(self::beta(), 'Beta')
            ->then(self::gamma(), 'Gamma');
    }

    /**
     * Three decks, none of them 49, one of them four tiles and one of them sized
     * by a setting the table wrote.
     */
    public function deck(GameContext $context): TileDeck
    {
        return match ($context->stage()->value()) {
            self::STAGE_ALPHA => TileDeck::of(
                Tile::of(0, 1),
                Tile::of(1, 2),
                Tile::of(2, 3),
                Tile::of(3, 4),
                Tile::of(4, 5),
                Tile::of(5, 6),
            ),
            self::STAGE_BETA => TileDeck::of(
                Tile::of(0, 0),
                Tile::of(1, 1),
                Tile::of(2, 2),
                Tile::of(3, 3),
            ),
            self::STAGE_GAMMA => $context->roomConfig()->get(self::CONFIG_GAMMA_DECK) === self::GAMMA_DECK_LONG
                ? TileDeck::of(Tile::of(6, 6), Tile::of(6, 5), Tile::of(6, 4), Tile::of(6, 3), Tile::of(6, 2), Tile::of(6, 1))
                : TileDeck::of(Tile::of(6, 6), Tile::of(6, 5), Tile::of(6, 4)),
            default => throw new RuntimeException($this->notAStage($context->stage())),
        };
    }

    /** One stage of three opens the board, which is the whole point of it. */
    public function visibility(GameContext $context): string
    {
        return $context->stage()->value() === self::STAGE_BETA
            ? Visibility::FACES_OPEN
            : Visibility::FACES_HIDDEN_UNTIL_TAKEN;
    }

    /**
     * `beta` clears its board as positions are taken, and it is the stage whose
     * faces are open: the two answers are orthogonal, and a projection that
     * carried an open face only while the position was still drawn would prove
     * they had been folded into one. The other two stages keep every taken
     * position on the board.
     */
    public function boardPresence(GameContext $context): string
    {
        return $context->stage()->value() === self::STAGE_BETA
            ? BoardPresence::TAKEN_LEAVES_BOARD
            : BoardPresence::TAKEN_STAYS_ON_BOARD;
    }

    /** One of each kind, so the generic form has all three to render. */
    public function roomConfigSpec(): RoomConfigSpec
    {
        return RoomConfigSpec::of(
            RoomConfigField::choice(
                self::CONFIG_GAMMA_DECK,
                'Size of the gamma deck',
                [self::GAMMA_DECK_SHORT, self::GAMMA_DECK_LONG],
                self::GAMMA_DECK_SHORT,
            ),
            RoomConfigField::text(self::CONFIG_BANNER, 'Banner', 'Hostile banner', self::BANNER_MAX_LENGTH),
            RoomConfigField::toggle(self::CONFIG_LOOP, 'Loop back to alpha', true),
        );
    }

    /**
     * A game of zero draws: this variant refuses a table it cannot play, and says
     * so with the one reason that is true of it.
     */
    public function onGameStarted(GameContext $context): Outcome
    {
        if ($context->seats()->count() > self::MAX_SEATS) {
            return Outcome::empty()->finishedBecause(FinishReason::RULES_ENDED_GAME);
        }

        return Outcome::empty()->withNextSeat(SeatNumber::first());
    }

    public function onTileDrawn(DrawContext $context): Outcome
    {
        return match ($context->stage()->value()) {
            self::STAGE_ALPHA => $this->onAlphaDraw($context),
            self::STAGE_BETA => $this->onBetaDraw($context),
            self::STAGE_GAMMA => $this->onGammaDraw($context),
            default => throw new RuntimeException($this->notAStage($context->stage())),
        };
    }

    /**
     * The answer decides where the game goes next, so a test that never answers
     * and a test that answers differently take different routes through the
     * framework.
     */
    public function onChoiceMade(ChoiceContext $context): Outcome
    {
        $next = match ($context->option()) {
            self::OPTION_BETA => self::beta(),
            self::OPTION_GAMMA => self::gamma(),
            default => throw new RuntimeException("'{$context->option()}' is not an answer ".self::ID.' offered.'),
        };

        return Outcome::of($this->banner($context->seat()))
            ->withRuleStatePatch([self::STATE_CHOSEN_STAGE => $context->option()])
            ->withNextStage($next);
    }

    /**
     * The first draw of `alpha` in the whole game parks the turn on a question.
     * A later one does not, which is what lets the stage be returned to and drain.
     */
    private function onAlphaDraw(DrawContext $context): Outcome
    {
        if ($context->priorDraws()->inStage(self::STAGE_ALPHA)->isEmpty()) {
            return Outcome::empty()->withPendingChoice(PendingChoice::of(
                $context->seat(),
                self::PROMPT_NEXT_STAGE,
                [self::OPTION_BETA, self::OPTION_GAMMA],
            ));
        }

        $outcome = Outcome::of($this->banner($context->seat()));

        return $this->exhausts($context)
            ? $outcome->finishedBecause(FinishReason::POOL_EXHAUSTED)
            : $outcome;
    }

    /**
     * The turn goes back to the seat that just took it, every time: four draws of
     * `beta` are four draws by one person. Draining it either loops back to a
     * stage already played or ends the game, according to the table's setting.
     */
    private function onBetaDraw(DrawContext $context): Outcome
    {
        $outcome = Outcome::of($this->banner($context->seat()))->withNextSeat($context->seat());

        if (! $this->exhausts($context)) {
            return $outcome;
        }

        return $context->roomConfig()->get(self::CONFIG_LOOP) === true
            ? $outcome->withNextStage(self::alpha())
            : $outcome->finishedBecause(FinishReason::RULES_ENDED_GAME);
    }

    /** Writes to the opaque state on every draw, and leaves backwards to `beta`. */
    private function onGammaDraw(DrawContext $context): Outcome
    {
        $outcome = Outcome::of($this->banner($context->seat()))
            ->withRuleStatePatch([self::STATE_LAST_GAMMA_TILE => $context->tile()->value()]);

        return $this->exhausts($context)
            ? $outcome->withNextStage(self::beta())
            : $outcome;
    }

    /** The pool with this draw applied, in the pool's own vocabulary (TR-54). */
    private function exhausts(DrawContext $context): bool
    {
        return $context->poolBefore()->take($context->position(), $context->seat())->isExhausted();
    }

    private function banner(SeatNumber $seat): Effect
    {
        return Effect::challenge($seat, self::CONFIG_BANNER);
    }

    private function notAStage(StageId $stage): string
    {
        return "'{$stage->value()}' is not a stage of ".self::ID.'.';
    }

    private static function alpha(): StageId
    {
        return StageId::fromString(self::STAGE_ALPHA);
    }

    private static function beta(): StageId
    {
        return StageId::fromString(self::STAGE_BETA);
    }

    private static function gamma(): StageId
    {
        return StageId::fromString(self::STAGE_GAMMA);
    }
}
