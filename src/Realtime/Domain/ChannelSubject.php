<?php

declare(strict_types=1);

namespace Src\Realtime\Domain;

/**
 * The minimum that channel authorization needs to know about a game.
 *
 * Deliberately NOT the aggregate: the realtime layer has no business being able
 * to touch a game in order to decide whether someone may watch it.
 */
final class ChannelSubject
{
    public function __construct(
        public readonly string $gameId,
        public readonly bool $isWatchable,
        public readonly string $controllerTokenHash,
    ) {}
}
