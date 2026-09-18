<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

/**
 * Entry kinds of the append-only log.
 *
 * A final class of constants, not a PHP enum: `enum-persistence` convention.
 *
 * Every kind is a write of the framework and none names a rule: a stage, a role
 * and a challenge are strings a ruleset owns, and none of them appears here.
 * `GameTest` sweeps the aggregate and fails if a kind it writes is missing from
 * `ALL`, or if `ALL` carries one nothing writes.
 */
final class MoveKind
{
    public const GAME_OPENED = 'game_opened';

    public const SEAT_RENAMED = 'seat_renamed';

    /** The table wrote its settings, which is a lobby-only write. */
    public const ROOM_CONFIGURED = 'room_configured';

    /** The table was renumbered, which is a lobby-only write. */
    public const SEATS_REORDERED = 'seats_reordered';

    public const GAME_STARTED = 'game_started';

    /** One position turned over, attributed to the current seat (TR-11). */
    public const TILE_DRAWN = 'tile_drawn';

    /** The seat a pending choice named answered it. */
    public const CHOICE_ANSWERED = 'choice_answered';

    public const GAME_ABANDONED = 'game_abandoned';

    public const ALL = [
        self::GAME_OPENED,
        self::SEAT_RENAMED,
        self::ROOM_CONFIGURED,
        self::SEATS_REORDERED,
        self::GAME_STARTED,
        self::TILE_DRAWN,
        self::CHOICE_ANSWERED,
        self::GAME_ABANDONED,
    ];

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }
}
