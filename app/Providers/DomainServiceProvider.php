<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Src\Game\Application\Command\CreateGame\CreateGameCommand;
use Src\Game\Application\Command\CreateGame\CreateGameHandler;
use Src\Game\Application\Command\RenameSeat\RenameSeatCommand;
use Src\Game\Application\Command\RenameSeat\RenameSeatHandler;
use Src\Game\Application\Query\GetGameState\GetGameStateHandler;
use Src\Game\Application\Query\GetGameState\GetGameStateQuery;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeHandler;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeQuery;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Service\StatePublisher;
use Src\Game\Infrastructure\Persistence\EloquentGameRepository;
use Src\Realtime\Application\Service\GameStatePublisher;
use Src\Realtime\Domain\ChannelAccess;
use Src\Realtime\Infrastructure\Persistence\RepositoryChannelAccess;
use Src\Shared\Domain\Bus\CommandBus;
use Src\Shared\Domain\Bus\QueryBus;
use Src\Shared\Domain\Service\Clock;
use Src\Shared\Infrastructure\Bus\LaravelCommandBus;
use Src\Shared\Infrastructure\Bus\LaravelQueryBus;
use Src\Shared\Infrastructure\Service\SystemClock;

/**
 * El único sitio donde se cablea `src/` al contenedor.
 *
 * Cada operación nueva = un Command/Query + su Handler, registrado aquí.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->bind(ChannelAccess::class, RepositoryChannelAccess::class);
        $this->app->bind(StatePublisher::class, GameStatePublisher::class);
        $this->app->bind(GameRepository::class, EloquentGameRepository::class);

        $this->app->singleton(CommandBus::class, function (Container $app): CommandBus {
            $bus = new LaravelCommandBus($app);
            $this->registerCommands($bus);

            return $bus;
        });

        $this->app->singleton(QueryBus::class, function (Container $app): QueryBus {
            $bus = new LaravelQueryBus($app);
            $this->registerQueries($bus);

            return $bus;
        });
    }

    private function registerCommands(LaravelCommandBus $bus): void
    {
        $bus->register(CreateGameCommand::class, CreateGameHandler::class);
        $bus->register(RenameSeatCommand::class, RenameSeatHandler::class);
    }

    private function registerQueries(LaravelQueryBus $bus): void
    {
        $bus->register(GetGameStateQuery::class, GetGameStateHandler::class);
        $bus->register(ResolveJoinCodeQuery::class, ResolveJoinCodeHandler::class);
    }
}
