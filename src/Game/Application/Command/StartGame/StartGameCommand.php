<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\StartGame;

use Src\Shared\Domain\Bus\Command;

final class StartGameCommand implements Command
{
    public function __construct(
        public readonly string $gameId,
        public readonly ?int $expectedVersion = null,
        public readonly ?string $requestId = null,
    ) {}
}
