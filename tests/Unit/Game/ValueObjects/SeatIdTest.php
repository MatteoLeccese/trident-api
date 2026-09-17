<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionParameter;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\SeatId;

final class SeatIdTest extends TestCase
{
    public function test_a_generated_identity_is_a_uuid_and_never_repeats(): void
    {
        $first = SeatId::random();
        $second = SeatId::random();

        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $first->value(),
        );
        $this->assertNotSame($first->value(), $second->value());
        $this->assertFalse($first->equals($second));
    }

    public function test_it_is_read_back_case_insensitively_and_compares_by_value(): void
    {
        $id = SeatId::fromString('0F8FAD5B-D9CB-469F-A165-70867728950E');

        $this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $id->value());
        $this->assertTrue($id->equals(SeatId::fromString('0f8fad5b-d9cb-469f-a165-70867728950e')));
    }

    public function test_anything_that_is_not_a_uuid_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SeatId::fromString('seat-2');
    }

    public function test_it_serialises_as_the_bare_string(): void
    {
        $id = SeatId::fromString('0f8fad5b-d9cb-469f-a165-70867728950e');

        $this->assertSame('"0f8fad5b-d9cb-469f-a165-70867728950e"', json_encode($id));
        $this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', (string) $id);
    }

    public function test_a_game_identity_cannot_stand_in_for_a_seats(): void
    {
        // The whole reason for a type of its own: the two are both UUIDs and the
        // compiler is what stops them being swapped.
        $this->assertFalse(is_a(GameId::class, SeatId::class, true));
        $this->assertFalse(is_a(SeatId::class, GameId::class, true));

        $parameter = new ReflectionParameter([Seat::class, 'reconstitute'], 'id');
        $type = $parameter->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(SeatId::class, $type->getName());
    }
}
