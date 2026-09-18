<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\ExpireIdleGames;

use Src\Shared\Domain\Bus\Command;

/**
 * Sweeps the games nobody has touched for long enough that the table has gone
 * home.
 *
 * It is the one command of the system that names no game. Every other write
 * answers a phone; this one answers a clock, which is why it carries neither an
 * `expectedVersion` nor a `requestId`: there is no screen holding a version to
 * guard against, and repeating the sweep is already harmless because a game that
 * has ended is not idle any more.
 */
final class ExpireIdleGamesCommand implements Command
{
    public function __construct(
        /** Minutes of silence after which a game is considered abandoned. */
        public readonly int $idleMinutes,

        /** The most games one sweep will end. */
        public readonly int $limit,
    ) {}
}
