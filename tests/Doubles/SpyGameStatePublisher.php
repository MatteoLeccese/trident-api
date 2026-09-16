<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Service\StatePublisher;

/**
 * Records what would have been emitted, without touching a socket.
 */
final class SpyGameStatePublisher implements StatePublisher
{
    /** @var list<GameSnapshot> */
    public array $published = [];

    public function publish(GameSnapshot $snapshot): void
    {
        $this->published[] = $snapshot;
    }
}
