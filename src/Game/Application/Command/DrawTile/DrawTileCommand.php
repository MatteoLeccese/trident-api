<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\DrawTile;

use Src\Shared\Domain\Bus\Command;

final class DrawTileCommand implements Command
{
    /**
     * @param  string  $position  the pool position as the route hands it over: digits, in canonical form
     */
    public function __construct(
        public readonly string $gameId,
        public readonly string $position,
        public readonly ?int $expectedVersion = null,
        public readonly ?string $requestId = null,
    ) {}
}
