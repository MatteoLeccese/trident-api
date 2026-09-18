<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules\Trident;

use RuntimeException;
use Src\Game\Domain\Model\SeatRoster;
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
 * The rules of the Trident, and the only production `RuleSet`.
 *
 * Every assertion it implements is numbered in
 * documentation/conventions/trident-rules.md and cited here by number; no rule is
 * restated in prose. `tests/Unit/Game/Rules/TridentRuleSetTest.php` carries one
 * test per applicable number, so the coverage of the specification is audited by
 * reading the two lists side by side.
 *
 * The whole game is two stages of the same move — a seat turns a position over
 * and each of the tile's two faces fires its challenge (TR-38) — plus one
 * exception: in `main` the face of three is answered by the trident and not by
 * whoever drew it (TR-44). That exception is the reason `Effect::challenge()`
 * carries a target, and it lives in this class alone: no column, no route, no
 * projection and no screen knows what a trident is.
 *
 * Stateless: it keeps no properties and writes nothing to `RuleState` beyond the
 * version the framework stamps (TR-19), so every decision is taken from the
 * context it is handed and from nothing else (TR-56).
 */
final class TridentRuleSet implements RuleSet
{
    /** The id a game is pinned to in `games.rule_set_id`. */
    public const ID = 'trident.v1';

    public const STATE_VERSION = 1;

    /** The two stages, in order (TR-16). */
    public const STAGE_ELECTION = 'election';

    public const STAGE_MAIN = 'main';

    /** An opaque role string: the framework never interprets it (TR-29). */
    public const ROLE_TRIDENT = 'trident';

    /** The face that elects, and the face the trident answers for (TR-44). */
    public const TRIDENT_FACE = 3;

    /**
     * The highest face this ruleset deals, and therefore the last of its seven
     * challenge keys (TR-43). It is a claim of `trident.v1` and not of the
     * framework: `Tile` accepts any digit, and a deck of another ruleset says
     * nothing about these keys. It agrees with the deck of TR-01 because TR-03
     * asserts one double per face against that deck.
     */
    public const MAX_FACE = 6;

    /** The prefix of the seven challenge keys, one per face (TR-43). */
    public const CHALLENGE_KEY_PREFIX = 'challenge.face.';

    /** The prefix of the per-stage presentation setting (TR-52). */
    public const DRAWN_TILES_KEY_PREFIX = 'drawn_tiles.';

    public const DRAWN_TILES_KEEP = 'keep';

    public const DRAWN_TILES_REMOVE = 'remove';

    public const DRAWN_TILES_OPTIONS = [self::DRAWN_TILES_KEEP, self::DRAWN_TILES_REMOVE];

    /** A challenge is painted at 96px on a television: three lines of it (TR-51). */
    public const CHALLENGE_MAX_LENGTH = 80;

    public function id(): string
    {
        return self::ID;
    }

    /** Nothing is kept between turns, so version one never has to migrate (TR-19). */
    public function stateVersion(): int
    {
        return self::STATE_VERSION;
    }

    public function stages(): StageSequence
    {
        return StageSequence::of(self::election(), 'Election')
            ->then(self::main(), 'Main game');
    }

    /**
     * The 49 ordered pairs, in both stages and at every table size (TR-01, TR-05).
     * The framework asks again on every stage change, which is what gives `main`
     * a pool of its own (TR-25, TR-31).
     */
    public function deck(GameContext $context): TileDeck
    {
        return TileDeck::standard();
    }

    /** Untaken faces are hidden in both stages, for everybody at once (TR-07). */
    public function visibility(GameContext $context): string
    {
        return Visibility::FACES_HIDDEN_UNTIL_TAKEN;
    }

    /**
     * Whether a taken position stays on the board, read off the setting this
     * ruleset declares for the stage in play (TR-52).
     *
     * This is the one answer of the seam this ruleset takes from the table rather
     * than from a rule, and it is answered here because the key is this ruleset's
     * own vocabulary: the framework does not know that a stage has a setting, and
     * a client that built `drawn_tiles.<stage>` for itself would be naming a rule
     * in a React file.
     *
     * Either value plays the same game — a taken position can never be taken
     * again and no outcome changes (TR-52) — so what it decides is what a screen
     * draws and nothing else. A stored value outside the declared options cannot
     * reach here: `RoomConfig::resolve()` replaced it with the default when play
     * began, and `remove` is the only value that clears the board.
     */
    public function boardPresence(GameContext $context): string
    {
        $setting = $context->roomConfig()->get(self::drawnTilesKey($context->stage()->value()));

        return $setting === self::DRAWN_TILES_REMOVE
            ? BoardPresence::TAKEN_LEAVES_BOARD
            : BoardPresence::TAKEN_STAYS_ON_BOARD;
    }

    /**
     * Seven challenge texts, one per face (TR-43, TR-51), and the two per-stage
     * presentation settings (TR-52). Rewriting any of them changes no draw
     * (TR-53): when a challenge fires is a rule, what it says is a setting.
     */
    public function roomConfigSpec(): RoomConfigSpec
    {
        $fields = [];

        for ($face = 0; $face <= self::MAX_FACE; $face++) {
            $fields[] = RoomConfigField::text(
                self::challengeKey($face),
                "Challenge for face {$face}",
                self::defaultChallenge($face),
                self::CHALLENGE_MAX_LENGTH,
            );
        }

        $fields[] = RoomConfigField::choice(
            self::drawnTilesKey(self::STAGE_ELECTION),
            'Drawn tiles during the election',
            self::DRAWN_TILES_OPTIONS,
            self::DRAWN_TILES_KEEP,
        );

        $fields[] = RoomConfigField::choice(
            self::drawnTilesKey(self::STAGE_MAIN),
            'Drawn tiles during the main game',
            self::DRAWN_TILES_OPTIONS,
            self::DRAWN_TILES_REMOVE,
        );

        return RoomConfigSpec::of(...$fields);
    }

    /**
     * The game opens in the election, which is the first stage this ruleset
     * declares (TR-17), and seat one draws first (TR-21, inference I2).
     */
    public function onGameStarted(GameContext $context): Outcome
    {
        return Outcome::empty()->withNextSeat(SeatNumber::first());
    }

    public function onTileDrawn(DrawContext $context): Outcome
    {
        return match ($context->stage()->value()) {
            self::STAGE_ELECTION => $this->onElectionDraw($context),
            self::STAGE_MAIN => $this->onMainDraw($context),
            default => throw new RuntimeException(
                "'{$context->stage()->value()}' is not a stage of ".self::ID.' (TR-16).',
            ),
        };
    }

    /**
     * Unreachable: no outcome of this ruleset carries a `PendingChoice`, so no
     * turn of this game ever waits for a human (TR-12, TR-13). Reaching it means
     * the framework invented a choice, which is lost state and not a table's
     * mistake: it renders as a 500 with a reference and is reported, the same
     * treatment a `main` stage with no trident gets.
     */
    public function onChoiceMade(ChoiceContext $context): Outcome
    {
        throw new RuntimeException(self::ID.' never asks a seat to choose (TR-12).');
    }

    public static function challengeKey(int $face): string
    {
        return self::CHALLENGE_KEY_PREFIX.$face;
    }

    public static function drawnTilesKey(string $stageId): string
    {
        return self::DRAWN_TILES_KEY_PREFIX.$stageId;
    }

    /**
     * Every flipped tile fires the challenges of its two faces (TR-23), and all
     * of them are answered by whoever drew it: no trident exists yet, so the face
     * of three behaves like the other six (TR-47, inference I1 — the one rule of
     * this ruleset that was inferred rather than stated, and revoking it changes
     * this condition and no schema).
     *
     * The double three ends the election on that draw and on no other (TR-24):
     * its drawer takes the role (TR-27, TR-48) and seat one opens `main`
     * (TR-32). The rest of the election pool is never played (TR-25).
     */
    private function onElectionDraw(DrawContext $context): Outcome
    {
        $elects = $context->tile()->equals(self::tridentTile());

        // The role is assigned before the challenges of the tile that assigned
        // it (TR-40).
        $outcome = $elects
            ? Outcome::of(Effect::assignRole($context->seat(), self::ROLE_TRIDENT))
            : Outcome::empty();

        $outcome = $this->withFaceChallenges($outcome, $context->tile(), $context->seat(), $context->seat());

        return $elects
            ? $outcome->withNextStage(self::main())->withNextSeat(SeatNumber::first())
            : $outcome;
    }

    /**
     * The same two challenges (TR-38), addressed by face: the trident answers for
     * the face of three (TR-44) and the drawer for the other six (TR-45). The
     * double three is a tile with two threes and nothing else (TR-46), so there
     * is no branch here on any tile.
     *
     * The stage ends when its pool runs out, which is the draw that empties it
     * (TR-34, TR-36), and nothing else ends the game by rule (TR-35).
     */
    private function onMainDraw(DrawContext $context): Outcome
    {
        $outcome = $this->withFaceChallenges(
            Outcome::empty(),
            $context->tile(),
            $context->seat(),
            $this->tridentSeat($context->seats()),
        );

        // The pool as it stands with this draw applied: the stage runs out when
        // nothing is left to turn over, which no rule has to count (TR-54).
        if (! $context->poolBefore()->take($context->position(), $context->seat())->isExhausted()) {
            return $outcome;
        }

        return $outcome->finishedBecause(FinishReason::POOL_EXHAUSTED);
    }

    /**
     * The left face and then the right one (TR-39), which fires a double's single
     * face twice (TR-41). A challenge carries the key and never the text (TR-42),
     * and its seat is the recipient and never the author.
     */
    private function withFaceChallenges(Outcome $outcome, Tile $tile, SeatNumber $drawer, SeatNumber $tridentFaceSeat): Outcome
    {
        foreach ([$tile->left(), $tile->right()] as $face) {
            $recipient = $face === self::TRIDENT_FACE ? $tridentFaceSeat : $drawer;

            $outcome = $outcome->withEffect(Effect::challenge($recipient, self::challengeKey($face)));
        }

        return $outcome;
    }

    /**
     * The trident is read from the seats and from nowhere else: the role lives in
     * `game_seats.roles`, there is no `trident_seat` column and no DTO of the
     * seam carries one (TR-29).
     *
     * Exactly one seat holds it once the election is over (TR-28). A `main` stage
     * where none does is an incident and not a table's mistake: it renders as a
     * 500 with a reference and is reported, rather than quietly retargeting
     * fourteen challenges at the wrong person (TR-58).
     */
    private function tridentSeat(SeatRoster $seats): SeatNumber
    {
        foreach ($seats->seats() as $seat) {
            if (in_array(self::ROLE_TRIDENT, $seat->roles(), true)) {
                return $seat->number();
            }
        }

        throw new RuntimeException('No seat carries the '.self::ROLE_TRIDENT.' role in '.self::STAGE_MAIN.' (TR-28).');
    }

    /**
     * A placeholder in English of the right shape and length, held until the
     * author delivers the seven texts (TR-51, TR-53). It is a marker of ours and
     * never invented content that could be mistaken for the table's own.
     */
    private static function defaultChallenge(int $face): string
    {
        return "Placeholder challenge for face {$face}: the table writes this one.";
    }

    private static function tridentTile(): Tile
    {
        return Tile::of(self::TRIDENT_FACE, self::TRIDENT_FACE);
    }

    private static function election(): StageId
    {
        return StageId::fromString(self::STAGE_ELECTION);
    }

    private static function main(): StageId
    {
        return StageId::fromString(self::STAGE_MAIN);
    }
}
