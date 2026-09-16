<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Service;

use DateTimeImmutable;
use DateTimeZone;
use Src\Shared\Domain\Service\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
