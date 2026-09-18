<?php

declare(strict_types=1);

namespace Src\Game\Application\Service;

use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Rules\RuleSet;
use Src\Game\Domain\Rules\RuleSetResolver;

/**
 * The ruleset one game is played by.
 *
 * A game in flight is pinned to `games.rule_set_id` and holds the id and never
 * the object, so changing `config/trident.php: default_rule_set` must not change
 * the meaning of a game that is already on a table. The default therefore
 * decides exactly one thing — which ruleset a game that has not started yet
 * would be pinned to — and this is the only class that reads it, because the
 * domain may not read a configuration file.
 *
 * It is not `GameProjector`, which resolves the same id for a different
 * purpose and projects a lobby with no ruleset at all: a lobby's board is empty
 * and its settings are raw, so a read of one must not depend on a default that a
 * deployment can change between two requests.
 */
final class GameRules
{
    public function __construct(
        private readonly RuleSetResolver $ruleSets,
        private readonly string $defaultRuleSetId,
    ) {}

    /**
     * The ruleset this write is decided by: the one the game was pinned to at
     * `start()`, or the deployment's default while it is pinned to none.
     */
    public function of(Game $game): RuleSet
    {
        return $this->ruleSets->resolve($game->ruleSetId() ?? $this->defaultRuleSetId);
    }
}
