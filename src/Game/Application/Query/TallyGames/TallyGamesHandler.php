<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\TallyGames;

use Src\Game\Domain\Model\GameTally;
use Src\Game\Domain\Repository\GameTallyReader;

final class TallyGamesHandler
{
    public function __construct(private readonly GameTallyReader $tallies) {}

    public function handle(TallyGamesQuery $query): GameTally
    {
        return $this->tallies->tally();
    }
}
