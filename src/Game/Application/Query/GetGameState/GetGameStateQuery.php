<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\GetGameState;

use Src\Shared\Domain\Bus\Query;

final class GetGameStateQuery implements Query
{
    public function __construct(public readonly string $gameId) {}
}
