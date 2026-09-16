<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\SeatNumber;

final class SeatNumberTest extends TestCase
{
    public function test_seats_are_one_based(): void
    {
        // One single convention, everywhere, forever. The old system had four
        // different index bases and none of them matched.
        $this->assertSame(1, SeatNumber::first()->value());
    }

    public function test_it_accepts_a_seat_inside_the_table(): void
    {
        $this->assertSame(7, SeatNumber::fromInt(7)->value());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function outsideTheTable(): array
    {
        return ['cero' => [0], 'negativo' => [-1], 'muy negativo' => [PHP_INT_MIN]];
    }

    #[DataProvider('outsideTheTable')]
    public function test_it_rejects_anything_below_one(string|int $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        SeatNumber::fromInt((int) $invalid);
    }

    public function test_it_is_never_falsy_in_javascript(): void
    {
        // 1-based on purpose: a 0 index is falsy once it crosses over to JS and is
        // lost in any `seat || fallback`.
        $this->assertGreaterThan(0, SeatNumber::first()->value());
    }

    public function test_seats_compare_by_value(): void
    {
        $this->assertTrue(SeatNumber::fromInt(3)->equals(SeatNumber::fromInt(3)));
        $this->assertFalse(SeatNumber::fromInt(3)->equals(SeatNumber::fromInt(4)));
    }

    public function test_it_serialises_as_a_number(): void
    {
        $this->assertSame('3', json_encode(SeatNumber::fromInt(3)));
    }
}
