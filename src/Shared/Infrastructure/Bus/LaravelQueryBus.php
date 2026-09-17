<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Bus;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Src\Shared\Domain\Bus\Query;
use Src\Shared\Domain\Bus\QueryBus;

final class LaravelQueryBus implements QueryBus
{
    /** @var array<class-string<Query>, class-string> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<Query>  $queryClass
     * @param  class-string  $handlerClass
     */
    public function register(string $queryClass, string $handlerClass): void
    {
        if (isset($this->handlers[$queryClass])) {
            throw new LogicException(
                "The query {$queryClass} already has a handler registered ({$this->handlers[$queryClass]}).",
            );
        }

        $this->handlers[$queryClass] = $handlerClass;
    }

    public function ask(Query $query): mixed
    {
        $queryClass = $query::class;

        if (! isset($this->handlers[$queryClass])) {
            throw new LogicException(
                "No handler is registered for {$queryClass}. Register it in DomainServiceProvider.",
            );
        }

        return $this->container->make($this->handlers[$queryClass])->handle($query);
    }
}
