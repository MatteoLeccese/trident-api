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
    protected $signature = 'trident:smoke {game : Id de la partida}';

    protected $description = 'Emite el estado de una partida por el socket, para depurar desde el salón.';

    public function handle(QueryBus $queries, StatePublisher $publisher): int
    {
        $gameId = (string) $this->argument('game');

        $snapshot = $queries->ask(new GetGameStateQuery($gameId));

        $publisher->publish($snapshot);

        $this->info("Estado emitido en presence-game.{$gameId}");
        $this->line('  versión: '.$snapshot->version()->value());
        $this->line('  asientos: '.count($snapshot->toArray()['seats']));
        $this->newLine();
        $this->comment('Si el televisor no se ha movido, el problema está en el socket, no en el estado.');

        return self::SUCCESS;
    }
}
