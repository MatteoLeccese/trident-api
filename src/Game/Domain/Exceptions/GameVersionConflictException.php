<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

/**
 * The write was built on a state that has already been replaced.
 *
 * It carries the **current projection** in `data`, so a stale phone heals from
 * the refusal itself instead of asking for the state in a second request
 * (documentation/conventions/state-versioning.md). That body is the same one
 * projection as every other delivery path, so it never carries a credential and
 * never carries the shuffle seed.
 */
final class GameVersionConflictException extends DomainException
{
    /**
     * @param  array<string, mixed>  $state  the current projection, for the client to heal from
     */
    public function __construct(
        private readonly array $state,
        int $expected,
        int $actual,
    ) {
        parent::__construct("That write expected version {$expected} and the game is at version {$actual}.");
    }

    public function errorCode(): string
    {
        return 'game_version_conflict';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->state;
    }
}
