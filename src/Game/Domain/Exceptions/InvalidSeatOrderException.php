<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * A reorder that is not a permutation of this table.
 *
 * The payload carries the seat numbers as they stand now, in the order they will
 * stand, so it has to name every seat of the table exactly once: a shorter, a
 * longer or a repeating list would leave a number unassigned or assigned twice.
 */
final class InvalidSeatOrderException extends DomainException
{
    public function __construct(string $message = 'That order is not a permutation of this table.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'seat_order_invalid';
    }
}
