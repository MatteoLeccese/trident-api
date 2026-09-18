<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use RuntimeException;

/**
 * A game in flight was handed a ruleset other than the one it was pinned to.
 *
 * Deliberately not a `DomainException`, for the same reason as
 * `UnknownRuleSetException`: nobody at the table did anything wrong, and a game
 * that is being played by the wrong rules is an incident an operator has to see.
 *
 * The id and not the state version: the same id one version on is an upgrade,
 * which the ruleset itself settles by reading `RuleState` and ending the game if
 * it cannot.
 */
final class RuleSetMismatchException extends RuntimeException
{
    public static function between(string $pinned, string $given): self
    {
        return new self("This game is pinned to '{$pinned}' and was handed '{$given}'.");
    }
}
