<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\CreateGame;

use Src\Shared\Domain\Bus\Command;

final class CreateGameCommand implements Command
{
    /**
     * @param  list<string>  $nicknames  In the order they were typed in: that is the table order.
     */
    public function __construct(public readonly array $nicknames) {}
}
