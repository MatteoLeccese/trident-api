<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Realtime\Application\Service\GameStatePublisher;
use Src\Realtime\Infrastructure\Broadcasting\GameStateChanged;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Support\GameProjectorFactory;
use Tests\Support\SourceInspector;
use Tests\TestCase;

/**
 * The old system's event was not dispatched from anywhere and read the cache in
 * its own constructor, so it broadcast `[]`. These tests pin down the opposite.
 */
final class GameStatePublisherTest extends TestCase
{
    private function game(): Game
    {
        return Game::open(
            GameId::fromString('0f8fad5b-d9cb-469f-a165-70867728950e'),
            JoinCode::fromString('K7QP3M'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-16 20:00:00'),
        );
    }

    /** The projection of that game, built exactly as a request builds it. */
    private function snapshot(): GameSnapshot
    {
        return GameProjectorFactory::make()->project($this->game());
    }

    public function test_publishing_dispatches_the_event(): void
    {
        Event::fake();

        $this->app->make(GameStatePublisher::class)->publish($this->snapshot());

        Event::assertDispatched(GameStateChanged::class);
    }

    public function test_the_event_broadcasts_synchronously(): void
    {
        // A queued snapshot broadcast reorders state, and the old system queued on
        // a driver with no worker. This is a settled decision.
        $event = new GameStateChanged($this->snapshot());

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
    }

    public function test_it_broadcasts_on_the_presence_channel_of_that_game(): void
    {
        $channel = new GameStateChanged($this->snapshot())->broadcastOn();

        $this->assertInstanceOf(PresenceChannel::class, $channel);
        $this->assertSame('presence-game.0f8fad5b-d9cb-469f-a165-70867728950e', $channel->name);
    }

    public function test_the_event_name_is_stable(): void
    {
        // The client listens for `.GameStateChanged`; renaming it breaks
        // the television.
        $this->assertSame('GameStateChanged', new GameStateChanged($this->snapshot())->broadcastAs());
    }

    public function test_the_payload_is_exactly_the_snapshot(): void
    {
        $snapshot = $this->snapshot();

        // Compared by value and then by bytes: the projection carries a JSON
        // object for `room_config`, and two encodings of the same empty map are
        // different PHP instances.
        $this->assertEquals($snapshot->toArray(), new GameStateChanged($snapshot)->broadcastWith());
        $this->assertSame(
            json_encode($snapshot->toArray()),
            json_encode(new GameStateChanged($snapshot)->broadcastWith()),
        );
    }

    public function test_the_event_never_reads_storage_to_build_its_payload(): void
    {
        // The old event did `Cache::get(...)` in its constructor and, with an empty
        // cache, broadcast `[]` to everyone.
        $source = SourceInspector::codeOf(
            base_path('src/Realtime/Infrastructure/Broadcasting/GameStateChanged.php'),
        );

        foreach (['Cache::', 'DB::', 'Repository', 'find('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_the_payload_never_carries_a_credential(): void
    {
        $encoded = json_encode(new GameStateChanged($this->snapshot())->broadcastWith());

        $this->assertIsString($encoded);

        foreach (['token', 'secret', 'hash'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    public function test_a_broadcast_failure_never_breaks_the_write(): void
    {
        // Under Octane the worker pool is fixed: if a downed Reverb propagated its
        // error, every POST would hang and the whole API would go down behind the
        // socket.
        Log::spy();
        Event::fake();
        Event::shouldReceive('dispatch')->andThrow(new \RuntimeException('reverb down'));

        $this->app->make(GameStatePublisher::class)->publish($this->snapshot());

        $this->addToAssertionCount(1);
    }

    public function test_exactly_one_file_dispatches_the_event(): void
    {
        // If dispatching gets scattered, nobody can reason about state ordering.
        $dispatchers = [];

        foreach (SourceInspector::phpFilesIn(base_path('src')) as $file) {
            $source = SourceInspector::codeOf($file);

            if (str_contains($source, 'GameStateChanged::dispatch')
                || str_contains($source, 'new GameStateChanged')) {
                $dispatchers[] = basename($file);
            }
        }

        $this->assertSame(['GameStatePublisher.php'], $dispatchers);
    }
}
