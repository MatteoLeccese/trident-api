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
                "La query {$queryClass} ya tiene un handler registrado ({$this->handlers[$queryClass]}).",
            );
        }

        $this->handlers[$queryClass] = $handlerClass;
    }

    public function ask(Query $query): mixed
    {
        $queryClass = $query::class;

        if (! isset($this->handlers[$queryClass])) {
            throw new LogicException(
                "No hay handler registrado para {$queryClass}. Regístralo en DomainServiceProvider.",
            );
        }

        return $this->container->make($this->handlers[$queryClass])->handle($query);
    }
}
