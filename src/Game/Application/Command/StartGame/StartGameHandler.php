<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\StartGame;

use Src\Game\Application\Service\GameRules;
use Src\Game\Application\Service\GameWriter;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\MoveKind;
use Src\Shared\Domain\Service\Clock;

/**
 * Play begins: the game is pinned to its ruleset, the table's settings are
 * resolved against that ruleset's spec and frozen, and the first stage's pool is
 * dealt. Every one of those decisions belongs to the aggregate; this handler
 * only says which ruleset the table is about to be pinned to.
 */
final class StartGameHandler
{
    public function __construct(
        private readonly GameWriter $writer,
        private readonly GameRules $rules,
        private readonly Clock $clock,
    ) {}

    public function handle(StartGameCommand $command): GameSnapshot
    {
        return $this->writer->write(
            $command->gameId,
            $command->expectedVersion,
            $command->requestId,
            MoveKind::GAME_STARTED,
            function (Game $game): void {
                $game->start($this->rules->of($game), $this->clock);
            },
        );
    }
}
