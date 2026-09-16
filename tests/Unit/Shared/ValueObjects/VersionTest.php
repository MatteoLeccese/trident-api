<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\Version;

final class VersionTest extends TestCase
{
    public function test_a_new_aggregate_starts_at_one(): void
    {
        $this->assertSame(1, Version::initial()->value());
    }

    public function test_next_increments_by_exactly_one(): void
    {
        // The client guard depends on this: incoming === current + 1 means "apply".
        $this->assertSame(2, Version::initial()->next()->value());
        $this->assertSame(48, Version::fromInt(47)->next()->value());
    }

    public function test_it_is_immutable(): void
    {
        $version = Version::initial();
        $version->next();

        $this->assertSame(1, $version->value());
    }

    public function test_versions_compare_by_value(): void
    {
        $this->assertTrue(Version::fromInt(47)->equals(Version::fromInt(47)));
        $this->assertFalse(Version::fromInt(47)->equals(Version::fromInt(48)));
        $this->assertTrue(Version::fromInt(48)->isAfter(Version::fromInt(47)));
        $this->assertFalse(Version::fromInt(47)->isAfter(Version::fromInt(47)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidVersions(): array
    {
        return ['cero' => [0], 'negativa' => [-1], 'muy negativa' => [PHP_INT_MIN]];
    }

    #[DataProvider('invalidVersions')]
    public function test_it_rejects_zero_and_negatives(int $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        Version::fromInt($invalid);
    }

    public function test_is_after_is_strict_not_greater_or_equal(): void
    {
        // If this were >=, the client guard would apply a repeated payload.
        $this->assertFalse(Version::fromInt(47)->isAfter(Version::fromInt(48)));
        $this->assertFalse(Version::fromInt(47)->isAfter(Version::fromInt(47)));
        $this->assertTrue(Version::fromInt(47)->isAfter(Version::fromInt(46)));
    }
}
