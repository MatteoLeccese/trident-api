<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Service\Clock;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Src\Shared\Infrastructure\Service\SystemClock;

final class ClockTest extends TestCase
{
    public function test_the_system_clock_reports_the_current_time_in_utc(): void
    {
        $now = (new SystemClock)->now();

        $this->assertSame('UTC', $now->getTimezone()->getName());
        $this->assertLessThan(5, abs($now->getTimestamp() - time()));
    }

    public function test_a_frozen_clock_does_not_move(): void
    {
        // Without this, a game's expiry is tested with sleep() or it is not tested.
        $clock = FrozenClock::at('2026-09-15 20:00:00');

        $first = $clock->now();
        $second = $clock->now();

        $this->assertEquals($first, $second);
        $this->assertSame('2026-09-15T20:00:00+00:00', $first->format(DateTimeImmutable::ATOM));
    }

    public function test_a_frozen_clock_can_be_advanced_deliberately(): void
    {
        $clock = FrozenClock::at('2026-09-15 20:00:00');

        $clock->advance('+90 minutes');

        $this->assertSame('2026-09-15T21:30:00+00:00', $clock->now()->format(DateTimeImmutable::ATOM));
    }

    public function test_both_clocks_satisfy_the_domain_contract(): void
    {
        $this->assertInstanceOf(Clock::class, new SystemClock);
        $this->assertInstanceOf(Clock::class, FrozenClock::at('2026-09-15 20:00:00'));
    }
}
