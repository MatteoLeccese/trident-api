<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class GameAlreadyFinishedException extends DomainException
{
    public function __construct(string $message = 'This game has already finished.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'game_already_finished';
    }
}
