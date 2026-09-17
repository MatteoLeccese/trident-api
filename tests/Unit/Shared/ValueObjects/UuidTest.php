<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\Uuid;

final class UuidTest extends TestCase
{
    public function test_random_generates_a_valid_uuid(): void
    {
        $uuid = Uuid::random();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $uuid->value(),
        );
    }

    public function test_random_produces_a_real_version_4_uuid(): void
    {
        // Without this, deleting the two bit-masking lines leaves the suite green.
        for ($i = 0; $i < 20; $i++) {
            $value = Uuid::random()->value();

            $this->assertSame('4', $value[14], 'The version nibble must be 4.');
            $this->assertContains($value[19], ['8', '9', 'a', 'b'], 'The variant nibble must be RFC 4122.');
        }
    }

    public function test_random_does_not_repeat(): void
    {
        $this->assertNotSame(Uuid::random()->value(), Uuid::random()->value());
    }

    public function test_it_round_trips_through_a_string(): void
    {
        $raw = '0f8fad5b-d9cb-469f-a165-70867728950e';

        $this->assertSame($raw, Uuid::fromString($raw)->value());
        $this->assertSame($raw, (string) Uuid::fromString($raw));
    }

    public function test_it_normalises_case(): void
    {
        $this->assertSame(
            '0f8fad5b-d9cb-469f-a165-70867728950e',
            Uuid::fromString('0F8FAD5B-D9CB-469F-A165-70867728950E')->value(),
        );
    }

    public function test_two_uuids_with_the_same_value_are_equal(): void
    {
        $raw = '0f8fad5b-d9cb-469f-a165-70867728950e';

        $this->assertTrue(Uuid::fromString($raw)->equals(Uuid::fromString($raw)));
        $this->assertFalse(Uuid::fromString($raw)->equals(Uuid::random()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'empty' => [''],
            'not a uuid' => ['nope'],
            'without dashes' => ['0f8fad5bd9cb469fa16570867728950e'],
            'too short' => ['0f8fad5b-d9cb-469f-a165-7086772895'],
            'non-hexadecimal character' => ['zf8fad5b-d9cb-469f-a165-70867728950e'],
            'with a trailing newline' => ["0f8fad5b-d9cb-469f-a165-70867728950e\n"],
            'with a newline and junk' => ["0f8fad5b-d9cb-469f-a165-70867728950e\nDROP TABLE games"],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_anything_that_is_not_a_uuid(string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        Uuid::fromString($invalid);
    }
}
