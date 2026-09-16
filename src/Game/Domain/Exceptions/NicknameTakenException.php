<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class NicknameTakenException extends DomainException
{
    public function __construct(string $message = 'Ese nombre ya está en uso en esta partida.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'nickname_taken';
    }
}
