<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Service;

use DateTimeImmutable;
use DateTimeZone;
use Src\Shared\Domain\Service\Clock;

/**
 * Test clock. Time only advances when the test says so.
 */
final class FrozenClock implements Clock
{
    private function __construct(private DateTimeImmutable $now) {}

    public static function at(string $instant): self
    {
        return new self(new DateTimeImmutable($instant, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
