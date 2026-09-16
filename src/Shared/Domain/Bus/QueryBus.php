<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Bus;

interface QueryBus
{
    /**
     * It is called `ask` and not `dispatch` on purpose: in a controller, a read
     * cannot be visually confused with a write.
     */
    public function ask(Query $query): mixed;
}
