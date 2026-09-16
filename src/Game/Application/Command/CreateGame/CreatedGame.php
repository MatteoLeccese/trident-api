<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\CreateGame;

use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\ValueObjects\ControllerToken;

/**
 * Result of opening a game.
 *
 * It carries the token in clear text because this is the **only** time it exists
 * outside the httpOnly cookie: the BFF takes it from here, stores it and strips it
 * from the response before it reaches the browser.
 */
final class CreatedGame
{
    public function __construct(
        public readonly GameSnapshot $snapshot,
        public readonly ControllerToken $controllerToken,
    ) {}
}
