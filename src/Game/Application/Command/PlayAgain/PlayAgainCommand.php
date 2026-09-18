<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\PlayAgain;

use Src\Shared\Domain\Bus\Command;

/**
 * It carries no `expected_version` and no write intention.
 *
 * A version guard would guard the wrong game: what this command addresses is a
 * game that is about to be closed, whatever version it is on, and the game it
 * answers with does not exist yet. An entry in the idempotency ledger could not
 * answer a repeat either, because the reply carries a plaintext
 * `ControllerToken` that exists exactly once and cannot be rebuilt from a stored
 * hash. See the handler.
 */
final class PlayAgainCommand implements Command
{
    public function __construct(public readonly string $gameId) {}
}
