<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use Src\Game\Domain\Exceptions\UnknownRuleSetException;

/**
 * Turns the `games.rule_set_id` of one game into the ruleset that game plays by.
 *
 * **It reads the game's own id and never a configuration key.** A game in flight
 * is pinned to the ruleset it was born with: `config/trident.php` decides only
 * which ruleset a new game is created with, so changing that default must not
 * change the meaning of a game already on a table. The domain has no way to
 * reach that key anyway: `ArchitectureTest` forbids the literal `config(` under
 * every Domain directory of src/, and refuses any class a domain file names
 * without importing it, which is the shape an unimported `Config::` would take.
 */
final class RuleSetResolver
{
    /** @var array<string, RuleSet> */
    private readonly array $ruleSets;

    /**
     * @param  iterable<RuleSet>  $ruleSets
     */
    public function __construct(iterable $ruleSets)
    {
        $indexed = [];

        foreach ($ruleSets as $ruleSet) {
            if (isset($indexed[$ruleSet->id()])) {
                throw new InvalidArgumentException("Two rulesets are registered under '{$ruleSet->id()}'.");
            }

            $indexed[$ruleSet->id()] = $ruleSet;
        }

        $this->ruleSets = $indexed;
    }

    public function resolve(string $ruleSetId): RuleSet
    {
        return $this->ruleSets[$ruleSetId] ?? throw UnknownRuleSetException::forId($ruleSetId);
    }

    public function has(string $ruleSetId): bool
    {
        return isset($this->ruleSets[$ruleSetId]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->ruleSets);
    }
}
