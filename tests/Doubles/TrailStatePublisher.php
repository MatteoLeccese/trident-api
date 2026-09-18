<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Service\StatePublisher;

/**
 * A publisher that writes its call into the same trail the repository writes
 * into, so that "published after the commit and never inside it" is one
 * assertion on one list.
 */
final class TrailStatePublisher implements StatePublisher
{
    public function __construct(private readonly TrailGameRepository $trail) {}

    public function publish(GameSnapshot $snapshot): void
    {
        $this->trail->trail[] = 'publish';
    }
}
