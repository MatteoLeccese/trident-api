<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\ResolveJoinCode;

use Src\Shared\Domain\Bus\Query;

final class ResolveJoinCodeQuery implements Query
{
    public function __construct(public readonly string $code) {}
}
