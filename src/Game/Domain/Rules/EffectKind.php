<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

/**
 * The closed vocabulary of what a rule can ask the table to do.
 *
 * A final class of constants and not a PHP enum: `enum-persistence` convention.
 * Effects are persisted as jsonb in `game_moves.payload`, so a new kind is a
 * constant here plus a branch in the client, and never an `ALTER TABLE`.
 *
 * There is no drinking kind and none may be added: drinking is the default
 * content of a challenge, not the mechanism, and no effect carries a quantity of
 * anything (TR-54).
 */
final class EffectKind
{
    /** Copy the application ships: trusted, translatable, parameterised. */
    public const ANNOUNCE = 'announce';

    /** Gives a seat an opaque role string the framework does not interpret (TR-29). */
    public const ASSIGN_ROLE = 'assign_role';

    /** Copy the table wrote: untrusted, bounded, addressed by config key (TR-42). */
    public const CHALLENGE = 'challenge';

    public const ALL = [self::ANNOUNCE, self::ASSIGN_ROLE, self::CHALLENGE];

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }
}
