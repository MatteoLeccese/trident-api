<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * A numeric position that the pool does not hold.
 *
 * It is a 422 and never a 500: the route constraint turns a non-numeric segment
 * into a 404 before the handler runs, so every position that reaches the domain
 * is a number the client may legitimately have got wrong.
 */
final class PoolPositionNotInPoolException extends DomainException
{
    public function __construct(string $message = 'That position is not in this pool.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'pool_position_not_in_pool';
    }
}
