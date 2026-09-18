<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Src\Game\Application\Command\ConfigureRoom\ConfigureRoomCommand;
use Src\Game\Application\Command\ConfigureRoom\ConfigureRoomHandler;
use Src\Game\Application\Command\CreateGame\CreateGameCommand;
use Src\Game\Application\Command\CreateGame\CreateGameHandler;
use Src\Game\Application\Command\DrawTile\DrawTileCommand;
use Src\Game\Application\Command\DrawTile\DrawTileHandler;
use Src\Game\Application\Command\PlayAgain\PlayAgainCommand;
use Src\Game\Application\Command\PlayAgain\PlayAgainHandler;
use Src\Game\Application\Command\RenameSeat\RenameSeatCommand;
use Src\Game\Application\Command\RenameSeat\RenameSeatHandler;
use Src\Game\Application\Command\ReorderSeats\ReorderSeatsCommand;
use Src\Game\Application\Command\ReorderSeats\ReorderSeatsHandler;
use Src\Game\Application\Command\StartGame\StartGameCommand;
use Src\Game\Application\Command\StartGame\StartGameHandler;
use Src\Game\Application\Query\GetGameState\GetGameStateHandler;
use Src\Game\Application\Query\GetGameState\GetGameStateQuery;
use Src\Game\Application\Query\GetRoomConfigSpec\GetRoomConfigSpecHandler;
use Src\Game\Application\Query\GetRoomConfigSpec\GetRoomConfigSpecQuery;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeHandler;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeQuery;
use Src\Game\Application\Service\GameProjector;
use Src\Game\Application\Service\GameRules;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\RuleSetResolver;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
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
 * The one place `src/` is wired to the container.
 *
 * Every new operation is a Command/Query plus its Handler, registered here.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->bind(ChannelAccess::class, RepositoryChannelAccess::class);
        $this->app->bind(StatePublisher::class, GameStatePublisher::class);
        $this->app->bind(GameRepository::class, EloquentGameRepository::class);

        // Every registered ruleset. A game is pinned to its own in
        // games.rule_set_id: this list says which ones exist, never which one a
        // game already in flight plays by.
        $this->app->singleton(RuleSetResolver::class, static fn (): RuleSetResolver => new RuleSetResolver([
            new TridentRuleSet,
        ]));

        // The one place `tv_idle_notice_minutes` is read: it is a per-deployment
        // value and the domain may not read a configuration file.
        $this->app->singleton(GameProjector::class, static fn (Container $app): GameProjector => new GameProjector(
            $app->make(RuleSetResolver::class),
            (int) config('trident.tv_idle_notice_minutes'),
        ));

        // The one place `default_rule_set` is read: it decides what a game that
        // has not started yet would be pinned to, and nothing else. A game in
        // flight holds its own id, so changing this key cannot change the meaning
        // of a game already on a table.
        $this->app->singleton(GameRules::class, static fn (Container $app): GameRules => new GameRules(
            $app->make(RuleSetResolver::class),
            (string) config('trident.default_rule_set'),
        ));

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
        $bus->register(ConfigureRoomCommand::class, ConfigureRoomHandler::class);
        $bus->register(ReorderSeatsCommand::class, ReorderSeatsHandler::class);
        $bus->register(StartGameCommand::class, StartGameHandler::class);
        $bus->register(DrawTileCommand::class, DrawTileHandler::class);
        $bus->register(PlayAgainCommand::class, PlayAgainHandler::class);
    }

    private function registerQueries(LaravelQueryBus $bus): void
    {
        $bus->register(GetGameStateQuery::class, GetGameStateHandler::class);
        $bus->register(ResolveJoinCodeQuery::class, ResolveJoinCodeHandler::class);
        $bus->register(GetRoomConfigSpecQuery::class, GetRoomConfigSpecHandler::class);
    }
}
