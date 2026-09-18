<?php

declare(strict_types=1);

namespace Src\Game\Domain\Exceptions;

use RuntimeException;

/**
 * A game is pinned to a ruleset this deployment does not provide.
 *
 * Deliberately not a `DomainException`: nobody at the table did anything wrong,
 * and the game cannot be read or played until somebody notices. It falls to the
 * global handler, which renders a 500 with a reference and writes the incident
 * to the log, instead of a quiet 4xx that no operator ever sees.
 */
final class UnknownRuleSetException extends RuntimeException
{
    public static function forId(string $ruleSetId): self
    {
        return new self("No ruleset is registered under '{$ruleSetId}'.");
    }
}
