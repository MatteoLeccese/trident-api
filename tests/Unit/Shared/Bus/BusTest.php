<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Bus;

use Illuminate\Container\Container;
use LogicException;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Bus\Command;
use Src\Shared\Domain\Bus\Query;
use Src\Shared\Infrastructure\Bus\LaravelCommandBus;
use Src\Shared\Infrastructure\Bus\LaravelQueryBus;

final class GreetCommand implements Command
{
    public function __construct(public readonly string $name) {}
}

class GreetHandler
{
    public function handle(GreetCommand $command): string
    {
        return "hola {$command->name}";
    }
}

final class CountQuery implements Query
{
    public function __construct(public readonly int $upTo) {}
}

final class CountHandler
{
    public function handle(CountQuery $query): int
    {
        return $query->upTo;
    }
}

final class OrphanCommand implements Command {}

final class OrphanQuery implements Query {}

final class BusTest extends TestCase
{
    public function test_a_command_reaches_its_registered_handler(): void
    {
        $bus = new LaravelCommandBus(new Container);
        $bus->register(GreetCommand::class, GreetHandler::class);

        $this->assertSame('hola Ana', $bus->dispatch(new GreetCommand('Ana')));
    }

    public function test_a_query_reaches_its_registered_handler(): void
    {
        $bus = new LaravelQueryBus(new Container);
        $bus->register(CountQuery::class, CountHandler::class);

        $this->assertSame(15, $bus->ask(new CountQuery(15)));
    }

    public function test_an_unregistered_command_fails_loudly(): void
    {
        // An unregistered handler is a programming error, not a business one:
        // it must blow up in development, not return a 422 to the player.
        $bus = new LaravelCommandBus(new Container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/OrphanCommand/');

        $bus->dispatch(new OrphanCommand);
    }

    public function test_an_unregistered_query_fails_loudly(): void
    {
        $bus = new LaravelQueryBus(new Container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/OrphanQuery/');

        $bus->ask(new OrphanQuery);
    }

    public function test_registering_the_same_command_twice_is_rejected(): void
    {
        // Two handlers for one command is silent ambiguity; it is caught at boot.
        $bus = new LaravelCommandBus(new Container);
        $bus->register(GreetCommand::class, GreetHandler::class);

        $this->expectException(LogicException::class);

        $bus->register(GreetCommand::class, GreetHandler::class);
    }

    public function test_handlers_are_resolved_from_the_container(): void
    {
        $container = new Container;
        $container->bind(GreetHandler::class, fn () => new class extends GreetHandler
        {
            public function handle(GreetCommand $command): string
            {
                return "sustituido: {$command->name}";
            }
        });

        $bus = new LaravelCommandBus($container);
        $bus->register(GreetCommand::class, GreetHandler::class);

        $this->assertSame('sustituido: Ana', $bus->dispatch(new GreetCommand('Ana')));
    }
}
