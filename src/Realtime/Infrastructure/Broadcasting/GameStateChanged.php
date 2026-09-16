<?php

declare(strict_types=1);

namespace Src\Realtime\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Src\Game\Domain\Model\GameSnapshot;

/**
 * A game's state, as it is, for everyone who is watching it.
 *
 * Two decisions that fix concrete bugs in the old system:
 *
 * 1. **It receives the snapshot already built.** The old event did `Cache::get()`
 *    in its own constructor and, with an empty cache, emitted `[]` to everybody.
 *    This one is incapable of reading storage: it has nothing to do it with.
 * 2. **`ShouldBroadcastNow`, not `ShouldBroadcast`.** A queued snapshot broadcast
 *    reorders state, and the old system queued onto a driver with no worker, so
 *    it never went out.
 */
final class GameStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private readonly GameSnapshot $snapshot) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('game.'.$this->snapshot->gameId()->value());
    }

    public function broadcastAs(): string
    {
        return 'GameStateChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->snapshot->toArray();
    }
}
