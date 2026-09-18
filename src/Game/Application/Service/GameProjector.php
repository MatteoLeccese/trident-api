<?php

declare(strict_types=1);

namespace Src\Game\Application\Service;

use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Rules\RuleSetResolver;

/**
 * The one place a `Game` becomes a `GameSnapshot`.
 *
 * The aggregate builds the projection, but two of its fields cannot be reached
 * from inside a domain that is plain PHP:
 *
 *  - **the ruleset.** A game is pinned to `games.rule_set_id` and holds the id,
 *    not the object, so that changing `config/trident.php: default_rule_set`
 *    cannot change the meaning of a game already on a table. The board's
 *    visibility is a rule (TR-07), so projecting the pool needs the object, and
 *    resolving an id into one is what `RuleSetResolver` is for.
 *  - **`tv_idle_notice_minutes`.** A deployment value, identical for every game,
 *    read from `config/trident.php`. The domain may not read a configuration file
 *    and a game may not carry a per-game copy of a value the table does not
 *    choose, so it is bound at the composition root and injected here.
 *
 * Every handler that returns or publishes state goes through this class, so
 * there is no second assembly of the projection to diverge from the first.
 */
final class GameProjector
{
    public function __construct(
        private readonly RuleSetResolver $ruleSets,
        private readonly int $tvIdleNoticeMinutes,
    ) {}

    public function project(Game $game): GameSnapshot
    {
        $ruleSetId = $game->ruleSetId();

        return $game->snapshot(
            $ruleSetId === null ? null : $this->ruleSets->resolve($ruleSetId),
            $this->tvIdleNoticeMinutes,
        );
    }
}
