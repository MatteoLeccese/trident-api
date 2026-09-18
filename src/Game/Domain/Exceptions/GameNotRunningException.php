<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * A position was turned over while the game was not in play.
 *
 * It covers a game still in the lobby, a game already over, and a game parked on
 * a pending choice: while one is open no position may be turned over, because
 * the turn is not finished until the question is answered.
 */
final class GameNotRunningException extends DomainException
{
    public function __construct(string $message = 'This game is not in play.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'game_not_running';
    }
}
