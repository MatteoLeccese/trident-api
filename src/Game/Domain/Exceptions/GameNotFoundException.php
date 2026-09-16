<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class GameNotFoundException extends DomainException
{
    public function __construct(string $message = 'Esa partida no existe o ya ha terminado.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'game_not_found';
    }

    public function status(): int
    {
        return 404;
    }
}
