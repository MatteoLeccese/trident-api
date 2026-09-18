<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * The table sent a setting the ruleset of this game does not declare.
 *
 * It names the keys and never discards them in silence: somebody typed
 * something and pressed save, and is entitled to know it was not saved
 * (documentation/conventions/room-config.md, the strict half of "strict form,
 * tolerant reader").
 */
final class UnknownRoomConfigKeyException extends DomainException
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(private readonly array $keys)
    {
        parent::__construct('This game has no setting called '.implode(', ', $keys).'.');
    }

    public function errorCode(): string
    {
        return 'room_config_key_unknown';
    }

    /**
     * @return array<string, list<string>>
     */
    public function data(): array
    {
        return ['keys' => $this->keys];
    }
}
