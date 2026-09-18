<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

/**
 * Why a game ended.
 *
 * A final class of constants and not a PHP enum: `enum-persistence` convention.
 *
 * Two kinds of reason live here and the distinction matters when reading them
 * back. A **ruleset** reason travels on an `Outcome` and answers "the rules say
 * this game is over". A **framework** reason is set by the code that abandons a
 * game without consulting any rule (TR-35), and answers "nobody is going to
 * finish this one".
 *
 * Every terminal path states one. A game that ends carrying `null` cannot be
 * told apart afterwards from one that ended some other way, and the only
 * question anybody will ever ask of this table is how many games the room
 * finished against how many it walked away from.
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

    /**
     * Nobody touched the phone for long enough that the table has clearly gone
     * home. Set by the framework, never by a ruleset.
     */
    public const IDLE_TIMEOUT = 'idle_timeout';

    /**
     * The table asked for another game, and this one closed to make room for it.
     * Set by the framework, never by a ruleset.
     *
     * It exists because a rematch and an expiry are both `abandoned`, and told
     * apart by nothing else: without this, "the room kept playing" and "the room
     * left" are the same row.
     */
    public const REPLACED_BY_REMATCH = 'replaced_by_rematch';

    public const ALL = [
        self::POOL_EXHAUSTED,
        self::RULESET_UPGRADED,
        self::RULES_ENDED_GAME,
        self::IDLE_TIMEOUT,
        self::REPLACED_BY_REMATCH,
    ];

    /** The reasons a ruleset is allowed to return on an `Outcome`. */
    public const FROM_RULES = [self::POOL_EXHAUSTED, self::RULESET_UPGRADED, self::RULES_ENDED_GAME];

    public static function isValid(string $reason): bool
    {
        return in_array($reason, self::ALL, true);
    }

    /**
     * Whether a ruleset is allowed to state this reason.
     *
     * A ruleset that answered `idle_timeout` would be claiming the table went
     * home, which is not a fact any rule is in a position to know.
     */
    public static function isFromRules(string $reason): bool
    {
        return in_array($reason, self::FROM_RULES, true);
    }
}
