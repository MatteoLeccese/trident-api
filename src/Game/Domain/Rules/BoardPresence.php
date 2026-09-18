<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

/**
 * Whether a position that has been taken stays on the board.
 *
 * A final class of constants and not a PHP enum, and not a boolean either, for
 * the same two reasons as `Visibility`: `enum-persistence`, and a third board
 * mode that a boolean could not express without changing every signature that
 * carries it.
 *
 * `RuleSet::boardPresence()` answers it per stage, never per viewer (TR-06), so
 * the phone and the television paint the same board. It is the one field of the
 * projection a ruleset may answer from a room setting rather than from a rule:
 * either value plays exactly the same game — a taken position can never be taken
 * again, and no outcome changes (TR-52) — so it addresses presentation and
 * nothing else.
 *
 * It is resolved **here and not in the client**. A client that read the setting
 * itself would have to build the key of the stage it is in, and a key that names
 * a stage is a rule living in a React file. What crosses the wire is therefore a
 * framework flag per position, `on_board`, and no client ever spells the setting
 * out.
 *
 * Like concealment, the decision belongs to the projection and not to storage:
 * the row keeps the whole pool, and `TilePool::project()` marks what the board
 * no longer shows.
 */
final class BoardPresence
{
    /** A taken position keeps its place on the board, face up. */
    public const TAKEN_STAYS_ON_BOARD = 'taken_stays_on_board';

    /** A taken position leaves the board, which draws an inert gap in its place. */
    public const TAKEN_LEAVES_BOARD = 'taken_leaves_board';

    public const ALL = [self::TAKEN_STAYS_ON_BOARD, self::TAKEN_LEAVES_BOARD];

    public static function isValid(string $presence): bool
    {
        return in_array($presence, self::ALL, true);
    }
}
