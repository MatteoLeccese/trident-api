<?php

declare(strict_types=1);

namespace Src\Game\Domain\Service;

use Src\Game\Domain\Model\GameSnapshot;

/**
 * Outbound port: "this game's state has changed, let whoever is watching it find
 * out".
 *
 * It lives in the context that NEEDS it, not in the one that implements it. That
 * way Game's handlers do not know Reverb exists, and the tests exercise them with
 * a spy without touching a socket.
 */
interface StatePublisher
{
    public function publish(GameSnapshot $snapshot): void;
}
