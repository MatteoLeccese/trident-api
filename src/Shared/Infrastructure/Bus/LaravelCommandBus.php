<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Bus;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Src\Shared\Domain\Bus\Command;
use Src\Shared\Domain\Bus\CommandBus;

final class LaravelCommandBus implements CommandBus
{
    /** @var array<class-string<Command>, class-string> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<Command>  $commandClass
     * @param  class-string  $handlerClass
     */
    public function register(string $commandClass, string $handlerClass): void
    {
        if (isset($this->handlers[$commandClass])) {
            throw new LogicException(
                "El comando {$commandClass} ya tiene un handler registrado ({$this->handlers[$commandClass]}).",
            );
        }

        $this->handlers[$commandClass] = $handlerClass;
    }

    public function dispatch(Command $command): mixed
    {
        $commandClass = $command::class;

        if (! isset($this->handlers[$commandClass])) {
            throw new LogicException(
                "No hay handler registrado para {$commandClass}. Regístralo en DomainServiceProvider.",
            );
        }

        return $this->container->make($this->handlers[$commandClass])->handle($command);
    }
}
