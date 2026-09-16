<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Service;

use DateTimeImmutable;

/**
 * Time is injected. Every TTL defect in the old system was a time defect, and
 * without this expiry is either tested with sleep() or not tested at all.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
