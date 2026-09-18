<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * A write that only the lobby accepts arrived after play began.
 *
 * The table's settings are frozen when play starts, and renumbering seats is
 * refused from the same moment: `game_moves.actor_seat` is a bare number, so a
 * seat renumbered mid-game would make the history name a different human.
 */
final class GameNotInLobbyException extends DomainException
{
    public function __construct(string $message = 'That can only be done before the game starts.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'game_not_in_lobby';
    }
}
