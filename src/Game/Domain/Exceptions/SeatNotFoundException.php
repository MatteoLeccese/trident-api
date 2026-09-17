<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class SeatNotFoundException extends DomainException
{
    public function __construct(string $message = 'That seat is not in this game.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'seat_not_found';
    }
}
