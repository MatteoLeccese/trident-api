<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * The table sent a declared setting with a value its field does not accept.
 *
 * Nothing is coerced: a value of the wrong type, an option outside the closed
 * set or a text over its limit is refused naming its key
 * (documentation/conventions/room-config.md).
 */
final class InvalidRoomConfigValueException extends DomainException
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(private readonly array $keys)
    {
        parent::__construct('This game does not accept that value for '.implode(', ', $keys).'.');
    }

    public function errorCode(): string
    {
        return 'room_config_value_invalid';
    }

    /**
     * @return array<string, list<string>>
     */
    public function data(): array
    {
        return ['keys' => $this->keys];
    }
}
