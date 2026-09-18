<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\RenameSeat;

use Src\Shared\Domain\Bus\Command;

final class RenameSeatCommand implements Command
{
    public function __construct(
        public readonly string $gameId,
        public readonly int $seat,
        public readonly string $nickname,
        public readonly ?int $expectedVersion = null,
        public readonly ?string $requestId = null,
    ) {}
}
