<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class RosterSizeException extends DomainException
{
    public function __construct(string $message = 'That is not a valid number of players.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'roster_size_invalid';
    }
}
