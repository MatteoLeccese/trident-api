<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Exceptions\PoolPositionNotInPoolException;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Shared\Domain\Exceptions\DomainException;

final class PoolPositionTest extends TestCase
{
    public function test_a_position_is_one_based(): void
    {
        $this->assertSame(1, PoolPosition::first()->value());
        $this->assertSame(1, PoolPosition::fromInt(1)->value());
        $this->assertSame(49, PoolPosition::fromInt(49)->value());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function positionsBelowTheFloor(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'far below' => [PHP_INT_MIN],
        ];
    }

    #[DataProvider('positionsBelowTheFloor')]
    public function test_a_position_below_the_first_is_refused(int $value): void
    {
        $this->expectException(PoolPositionNotInPoolException::class);

        PoolPosition::fromInt($value);
    }

    public function test_an_out_of_range_position_is_a_business_failure_and_never_a_crash(): void
    {
        // The route constraint turns a non-numeric segment into a 404 before the
        // handler runs, so every position that gets here is a number: it has to
        // come out as a 422 with a code the client branches on, not as a 500.
        try {
            PoolPosition::fromInt(0);
            $this->fail('An out-of-range position must be refused.');
        } catch (PoolPositionNotInPoolException $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('pool_position_not_in_pool', $e->errorCode());
            $this->assertSame(422, $e->status());
        }
    }

    public function test_it_reads_the_position_the_route_carries(): void
    {
        $this->assertSame(7, PoolPosition::fromString('7')->value());
        $this->assertSame(49, PoolPosition::fromString('49')->value());
    }

    public function test_a_number_too_big_for_an_integer_is_refused(): void
    {
        // `(int) '99999999999999999999'` saturates to PHP_INT_MAX in silence.
        $this->expectException(PoolPositionNotInPoolException::class);

        PoolPosition::fromString('99999999999999999999');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function segmentsThatAreNotPositions(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'leading zero' => ['007'],
            'signed' => ['+1'],
            'negative' => ['-1'],
            'decimal' => ['1.0'],
            'spaced' => [' 1'],
            'trailing newline' => ["1\n"],
            'word' => ['first'],
        ];
    }

    #[DataProvider('segmentsThatAreNotPositions')]
    public function test_a_segment_that_is_not_a_canonical_position_is_refused(string $segment): void
    {
        $this->expectException(PoolPositionNotInPoolException::class);

        PoolPosition::fromString($segment);
    }

    public function test_the_refusal_never_echoes_what_the_client_sent(): void
    {
        // A domain failure's message crosses the wire in the error envelope.
        try {
            PoolPosition::fromString('<script>alert(1)</script>');
            $this->fail('That is not a position.');
        } catch (PoolPositionNotInPoolException $e) {
            $this->assertStringNotContainsString('script', $e->getMessage());
        }
    }

    public function test_it_compares_by_value(): void
    {
        $this->assertTrue(PoolPosition::fromInt(7)->equals(PoolPosition::fromInt(7)));
        $this->assertFalse(PoolPosition::fromInt(7)->equals(PoolPosition::fromInt(8)));
    }

    public function test_it_serialises_as_the_bare_integer(): void
    {
        $this->assertSame('7', json_encode(PoolPosition::fromInt(7)));
    }
}
