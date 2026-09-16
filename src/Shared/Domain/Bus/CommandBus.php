<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Bus;

interface CommandBus
{
    public function dispatch(Command $command): mixed;
}
