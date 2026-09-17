<?php

declare(strict_types=1);

namespace Src\Realtime\Application\Service;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Service\StatePublisher;
use Src\Realtime\Infrastructure\Broadcasting\GameStateChanged;
use Throwable;

/**
 * The **only** place in the system that emits state. A test enforces it.
 *
 * It is called after the write has committed, never before: emitting a state
 * that is later undone leaves the televisions narrating a game that never
 * happened.
 *
 * And it swallows its own errors on purpose. Under Octane the worker pool is
 * fixed: if a Reverb that is down or stuck propagated its exception, every POST
 * would hang and the whole API would go down behind a socket failure. Clients
 * recover on their own from a lost frame — through the version gap and through
 * the reconciliation poll —, so hanging here buys nothing.
 */
final class GameStatePublisher implements StatePublisher
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function publish(GameSnapshot $snapshot): void
    {
        try {
            $this->events->dispatch(new GameStateChanged($snapshot));
        } catch (Throwable $e) {
            $this->logger->warning('Could not broadcast the game state.', [
                'game_id' => $snapshot->gameId()->value(),
                'version' => $snapshot->version()->value(),
                'exception' => $e::class,
            ]);
        }
    }
}
