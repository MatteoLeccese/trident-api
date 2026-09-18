<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\ReorderSeats;

use Src\Shared\Domain\Bus\Command;

final class ReorderSeatsCommand implements Command
{
    /**
     * @param  list<int>  $order  the absolute permutation: the seat numbered $order[0] becomes seat 1
     */
    public function __construct(
        public readonly string $gameId,
        public readonly array $order,
        public readonly ?int $expectedVersion = null,
        public readonly ?string $requestId = null,
    ) {}
}
