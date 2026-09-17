<?php

declare(strict_types=1);

namespace Src\Realtime\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Game\Application\Query\GetGameState\GetGameStateQuery;
use Src\Game\Domain\Service\StatePublisher;
use Src\Shared\Domain\Bus\QueryBus;

/**
 * Emits the current state of a game on demand.
 *
 * It exists for one very concrete reason: **a television has no devtools and no
 * console**. When the big screen does not move, this is the only thing that
 * lets you separate "the socket is not arriving" from "the state is not
 * changing", while standing in the living room with the phone in hand.
 */
final class SmokeCommand extends Command
{
    protected $signature = 'trident:smoke {game : Game id}';

    protected $description = 'Broadcasts a game state over the socket, to debug from the living room.';

    public function handle(QueryBus $queries, StatePublisher $publisher): int
    {
        $gameId = (string) $this->argument('game');

        $snapshot = $queries->ask(new GetGameStateQuery($gameId));

        $publisher->publish($snapshot);

        $this->info("State broadcast on presence-game.{$gameId}");
        $this->line('  version: '.$snapshot->version()->value());
        $this->line('  seats: '.count($snapshot->toArray()['seats']));
        $this->newLine();
        $this->comment('If the television has not moved, the problem is the socket, not the state.');

        return self::SUCCESS;
    }
}
