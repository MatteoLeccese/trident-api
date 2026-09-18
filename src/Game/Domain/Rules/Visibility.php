<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

/**
 * Whether the faces of untaken positions are published.
 *
 * A final class of constants and not a PHP enum: `enum-persistence` convention.
 * It is not a boolean either: a boolean cannot express a third mode without
 * changing every signature that carries it, and a ruleset that opens the board
 * midway through is exactly that third mode.
 *
 * `RuleSet::visibility()` answers it per stage, never per viewer (TR-06): the
 * phone and the television receive the same bytes, because the phone is passed
 * from hand to hand and whoever holds it must not be able to read the board in
 * the devtools.
 *
 * The concealment itself belongs to the projection and not to storage: the row
 * keeps every face (TR-09) and `TilePool::project()` drops the ones this value
 * hides.
 */
final class Visibility
{
    /** An untaken position projects `tile: null` (TR-07). */
    public const FACES_HIDDEN_UNTIL_TAKEN = 'faces_hidden_until_taken';

    /** Every position projects its face, taken or not. */
    public const FACES_OPEN = 'faces_open';

    public const ALL = [self::FACES_HIDDEN_UNTIL_TAKEN, self::FACES_OPEN];

    public static function isValid(string $visibility): bool
    {
        return in_array($visibility, self::ALL, true);
    }
}
