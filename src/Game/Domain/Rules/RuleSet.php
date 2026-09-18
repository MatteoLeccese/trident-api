<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use Src\Game\Domain\ValueObjects\TileDeck;

/**
 * **A game rule may only live behind this interface.** The database schema
 * encodes no rule semantics: there is no `trident_seat` column, no
 * `challenge_pip_3` and no table of challenges, so TR-29 and TR-43 hold without
 * a single migration and the same schema serves any variant the house plays
 * tomorrow.
 *
 * Ten methods and **three** execution entry points. The three that answer a
 * question about a stage — `deck()`, `visibility()` and `boardPresence()` — take
 * a `GameContext` and not a bare stage: a ruleset may want to size its deck to
 * the table, to open the rest of the board once its state says something has
 * happened, or to read a setting the table wrote, and narrowing the input would
 * force inventing a fake stage to express that — the framework dictating the
 * shape of a rule. `trident.v1` exercises the context in one of the three
 * (TR-05, TR-07, TR-52), and the signature stands anyway: the seam is fixed by
 * the worst ruleset it has to accept, not by the only one there is.
 *
 * The third entry point is `onChoiceMade()`. It was not in the seam as first
 * written, and the hostile double of `tests/Unit/Game/Doubles/` is what added
 * it: an `Outcome` can park a game on a `PendingChoice`, and an answer that
 * re-enters nowhere parks it forever. `trident.v1` never raises one (TR-12) and
 * treats a call to it as an incident.
 *
 * The success criterion, declared in advance: when a new ruleset arrives,
 * `git diff --stat database/migrations/` of the phase that introduces it is
 * empty.
 */
interface RuleSet
{
    /** The id a game is pinned to in `games.rule_set_id`, e.g. "trident.v1". */
    public function id(): string;

    /** The schema version of everything this ruleset writes to `RuleState`. */
    public function stateVersion(): int;

    /** Every stage, in declaration order, with the label a screen paints. */
    public function stages(): StageSequence;

    /** The tiles this stage plays with. The framework asks again on every stage change (TR-31). */
    public function deck(GameContext $context): TileDeck;

    /**
     * Whether the faces nobody has taken are published, per stage and never per
     * viewer (TR-06).
     *
     * One of `Visibility::ALL`. A string and not a type, because a closed set is
     * a final class of constants and never a native enum: `enum-persistence`.
     */
    public function visibility(GameContext $context): string;

    /**
     * Whether a position that has been taken stays on the board, per stage and
     * never per viewer (TR-06).
     *
     * One of `BoardPresence::ALL`, a string for the same reason as the visibility
     * above. It is the one answer of this interface that is **presentation and not
     * a rule**: both values play the same game (TR-52), so a ruleset is free to
     * read it off a setting its own `roomConfigSpec()` declared. It is answered
     * here because the key of that setting is the ruleset's own vocabulary, and a
     * client that built it would be naming a rule.
     */
    public function boardPresence(GameContext $context): string;

    /** The settings this ruleset declares, which is what the lobby form is generated from. */
    public function roomConfigSpec(): RoomConfigSpec;

    /** The first decision of a game: it runs before a cursor exists (TR-17). */
    public function onGameStarted(GameContext $context): Outcome;

    /** The decision of every turn: a seat turned a position over. */
    public function onTileDrawn(DrawContext $context): Outcome;

    /**
     * The seat a `PendingChoice` named has answered it.
     *
     * The framework has already checked that the answer comes from that seat and
     * is one of the options offered, so a ruleset may branch on it with a `match`
     * whose default is an incident. A ruleset that never returns a
     * `PendingChoice` can never be called here.
     */
    public function onChoiceMade(ChoiceContext $context): Outcome;
}
