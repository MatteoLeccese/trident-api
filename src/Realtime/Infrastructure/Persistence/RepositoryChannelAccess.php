<?php

declare(strict_types=1);

namespace Src\Realtime\Infrastructure\Persistence;

use InvalidArgumentException;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Realtime\Domain\ChannelAccess;
use Src\Realtime\Domain\ChannelSubject;

final class RepositoryChannelAccess implements ChannelAccess
{
    public function __construct(private readonly GameRepository $games) {}

    public function lookup(string $gameId): ?ChannelSubject
    {
        try {
            $game = $this->games->find(GameId::fromString($gameId));
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($game === null) {
            return null;
        }

        return new ChannelSubject(
            $game->id()->value(),
            // A finished game is not watched: the television paints "it has
            // ended" instead of staying frozen on the last frame.
            ! GameStatus::isTerminal($game->status()),
            $game->controllerTokenHash(),
        );
    }
}
