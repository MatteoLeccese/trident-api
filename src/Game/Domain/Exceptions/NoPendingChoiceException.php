<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/** An answer arrived for a question this game is not waiting on. */
final class NoPendingChoiceException extends DomainException
{
    public function __construct(string $message = 'Nothing is waiting for an answer in this game.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'no_pending_choice';
    }
}
