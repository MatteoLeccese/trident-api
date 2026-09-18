<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\ConfigureRoom;

use Src\Shared\Domain\Bus\Command;

final class ConfigureRoomCommand implements Command
{
    /**
     * @param  array<string, mixed>  $settings  the flat map of dotted keys the table submitted
     */
    public function __construct(
        public readonly string $gameId,
        public readonly array $settings,
        public readonly ?int $expectedVersion = null,
        public readonly ?string $requestId = null,
    ) {}
}
