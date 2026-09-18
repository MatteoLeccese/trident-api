<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\StageId;

/**
 * A stage is an opaque string the RuleSet owns. Nothing here knows what one is.
 */
final class StageIdTest extends TestCase
{
    public function test_it_reads_back_exactly_what_was_stored(): void
    {
        $this->assertSame('election', StageId::fromString('election')->value());
        $this->assertSame('main', StageId::fromString('main')->value());
    }

    public function test_two_stages_with_the_same_id_are_the_same_stage(): void
    {
        $this->assertTrue(StageId::fromString('main')->equals(StageId::fromString('main')));
        $this->assertFalse(StageId::fromString('main')->equals(StageId::fromString('election')));
    }

    public function test_it_travels_as_a_plain_string(): void
    {
        $this->assertSame('"main"', json_encode(StageId::fromString('main')));
    }

    /**
     * @return list<array{string}>
     */
    public static function refusedStages(): array
    {
        return [
            [''],
            ['Main'],
            ['1main'],
            ['main game'],
            ['main-game'],
            ['main.game'],
            ["main\n"],
            [str_repeat('m', StageId::MAX_LENGTH + 1)],
        ];
    }

    /**
     * The format is all the framework claims: something it can store, hash into
     * the shuffle stream and use as the tail of a configuration key.
     */
    #[DataProvider('refusedStages')]
    public function test_it_refuses_what_it_cannot_store_or_hash(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        StageId::fromString($value);
    }

    public function test_it_accepts_the_longest_id_it_declares(): void
    {
        $longest = str_repeat('m', StageId::MAX_LENGTH);

        $this->assertSame($longest, StageId::fromString($longest)->value());
    }
}
