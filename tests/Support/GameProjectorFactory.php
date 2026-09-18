<?php

declare(strict_types=1);

namespace Tests\Support;

use Src\Game\Application\Service\GameProjector;
use Src\Game\Domain\Rules\RuleSetResolver;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;

/**
 * The real projector, for the tests that run without a container.
 *
 * It is not a double: it wires the production ruleset and the production
 * resolver, so a unit test projects a game exactly as a request does. Only the
 * deployment value is supplied by hand, because there is no configuration file
 * to read it from here.
 */
final class GameProjectorFactory
{
    /**
     * Twin of `config/trident.php: tv_idle_notice_minutes`. A test that asserts
     * the projected number asserts THIS one, and the value the application ships
     * is pinned by `tests/Unit/Shared/TridentConfigTest.php`.
     */
    public const TV_IDLE_NOTICE_MINUTES = 30;

    public static function make(int $tvIdleNoticeMinutes = self::TV_IDLE_NOTICE_MINUTES): GameProjector
    {
        return new GameProjector(new RuleSetResolver([new TridentRuleSet]), $tvIdleNoticeMinutes);
    }
}
