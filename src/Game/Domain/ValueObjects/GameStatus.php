<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

/**
 * A game's status, owned by the game framework (not by the RuleSet).
 *
 * A final class of constants and not a PHP enum: `enum-persistence` convention.
 * It is stored as VARCHAR and validated in the application, which keeps Postgres
 * and SQLite interchangeable in the tests.
 *
 * Not to be confused with `stage`, which is an **opaque string owned by the
 * RuleSet**. The frontend never branches on a stage: it paints its label.
 */
final class GameStatus
{
    /** People joining; play has not started yet. */
    public const LOBBY = 'lobby';

    /** In play. */
    public const RUNNING = 'running';

    /**
     * In play, and parked on a `PendingChoice` a named seat has to answer before
     * anything else may happen.
     *
     * Not terminal: the game is still live, the channel is still open and the
     * code is still valid. It exists because an `Outcome` can carry a choice, and
     * a game that is waiting for one must refuse a draw — a state the framework
     * cannot express as `running` without letting the next position be turned
     * over while a rule is still mid-decision. `trident.v1` never reaches it
     * (TR-12).
     */
    public const AWAITING_CHOICE = 'awaiting_choice';

    /** Finished as the rules dictate. */
    public const FINISHED = 'finished';

    /** Expired through inactivity, or abandoned. */
    public const ABANDONED = 'abandoned';

    public const ALL = [self::LOBBY, self::RUNNING, self::AWAITING_CHOICE, self::FINISHED, self::ABANDONED];

    private const TERMINAL = [self::FINISHED, self::ABANDONED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** A terminal status closes the channel and releases the game's code. */
    /**
     * The statuses nothing moves out of.
     *
     * Exposed so a query can exclude them at the database rather than loading
     * every game that has ever been played to ask each one in PHP.
     *
     * @return list<string>
     */
    public static function terminal(): array
    {
        return self::TERMINAL;
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
