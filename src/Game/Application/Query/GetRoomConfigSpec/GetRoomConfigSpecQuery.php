<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\GetRoomConfigSpec;

use Src\Shared\Domain\Bus\Query;

final class GetRoomConfigSpecQuery implements Query
{
    public function __construct(public readonly string $gameId) {}
}
