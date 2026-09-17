<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * A second take of the same position.
 *
 * A taken position is marked and never removed (TR-08), so a stale phone can
 * still address it; taking it again is refused rather than replayed.
 */
final class PoolPositionAlreadyTakenException extends DomainException
{
    public function __construct(string $message = 'That position has already been taken.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'pool_position_already_taken';
    }
}
