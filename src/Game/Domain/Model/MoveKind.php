<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

/**
 * Entry kinds of the append-only log.
 *
 * A final class of constants, not a PHP enum: `enum-persistence` convention.
 */
final class MoveKind
{
    public const GAME_OPENED = 'game_opened';

    public const SEAT_RENAMED = 'seat_renamed';

    public const GAME_ABANDONED = 'game_abandoned';

    public const ALL = [self::GAME_OPENED, self::SEAT_RENAMED, self::GAME_ABANDONED];

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }
}
