<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

/**
 * Why a ruleset ended a game, carried by `Outcome`.
 *
 * A final class of constants and not a PHP enum: `enum-persistence` convention.
 *
 * Only a ruleset sets one. Expiry through inactivity lands in `abandoned`, which
 * is terminal for the framework and never consults the rules (TR-35).
 */
final class FinishReason
{
    /** The stage's pool ran out (TR-34). */
    public const POOL_EXHAUSTED = 'pool_exhausted';

    /**
     * A ruleset read a `RuleState` written by an earlier version of itself and
     * could not migrate it, so it ends the game instead of misreading it.
     */
    public const RULESET_UPGRADED = 'ruleset_upgraded';

    /**
     * The ruleset decided the game is over, for a reason of its own that no
     * other constant states.
     *
     * It exists because a ruleset that ends a game has to be able to say so
     * truthfully: the other two are specific claims, and a ruleset that refuses
     * to play a table it cannot handle would have to lie with one of them. No
     * production ruleset emits it — `trident.v1` ends only on an exhausted pool
     * (TR-34, TR-35).
     */
    public const RULES_ENDED_GAME = 'rules_ended_game';

    public const ALL = [self::POOL_EXHAUSTED, self::RULESET_UPGRADED, self::RULES_ENDED_GAME];

    public static function isValid(string $reason): bool
    {
        return in_array($reason, self::ALL, true);
    }
}
