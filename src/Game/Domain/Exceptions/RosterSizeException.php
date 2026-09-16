<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class RosterSizeException extends DomainException
{
    public function __construct(string $message = 'El número de jugadores no es válido.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'roster_size_invalid';
    }
}
