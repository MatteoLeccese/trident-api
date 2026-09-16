<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Src\Shared\Domain\Bus\CommandBus;
use Src\Shared\Domain\Bus\QueryBus;
use Src\Shared\Domain\Service\Clock;
use Src\Shared\Infrastructure\Bus\LaravelCommandBus;
use Src\Shared\Infrastructure\Bus\LaravelQueryBus;
use Src\Shared\Infrastructure\Service\SystemClock;
use Tests\TestCase;

/**
 * `DomainServiceProvider` wires the whole kernel into the container, and until now
 * removing it from bootstrap/providers.php left the suite green.
 */
final class ContainerWiringTest extends TestCase
{
    public function test_the_clock_resolves_to_the_system_clock(): void
    {
        $this->assertInstanceOf(SystemClock::class, $this->app->make(Clock::class));
    }

    public function test_the_buses_resolve_to_their_laravel_implementations(): void
    {
        $this->assertInstanceOf(LaravelCommandBus::class, $this->app->make(CommandBus::class));
        $this->assertInstanceOf(LaravelQueryBus::class, $this->app->make(QueryBus::class));
    }

    public function test_the_buses_are_singletons(): void
    {
        // If they were not, the handler map would be rebuilt on every resolution
        // and a duplicate registration would never be detected.
        $this->assertSame($this->app->make(CommandBus::class), $this->app->make(CommandBus::class));
        $this->assertSame($this->app->make(QueryBus::class), $this->app->make(QueryBus::class));
        $this->assertSame($this->app->make(Clock::class), $this->app->make(Clock::class));
    }

    public function test_resolving_the_buses_runs_every_handler_registration(): void
    {
        // A handler registered twice throws when the bus is built. By forcing the
        // resolution here, that fails in CI and not on a game's first request.
        $this->app->make(CommandBus::class);
        $this->app->make(QueryBus::class);

        $this->addToAssertionCount(1);
    }
}
