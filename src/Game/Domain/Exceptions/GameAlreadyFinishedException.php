<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class GameAlreadyFinishedException extends DomainException
{
    public function __construct(string $message = 'Esta partida ya ha terminado.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'game_already_finished';
    }
}
