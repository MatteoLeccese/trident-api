<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\TileDeck;
use Src\Game\Domain\ValueObjects\TilePool;

/**
 * The shuffle's seed is a server secret: whoever holds it replays the shuffle
 * and reads every face-down position (TR-09).
 */
final class SeedTest extends TestCase
{
    private const LITERAL = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

    public function test_it_generates_enough_entropy_to_be_unguessable(): void
    {
        $seed = Seed::generate();

        // 32 bytes in base64url: 43 characters with no padding.
        $this->assertSame(43, strlen($seed->value()));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $seed->value());
    }

    public function test_it_does_not_repeat(): void
    {
        $seeds = [];

        for ($i = 0; $i < 100; $i++) {
            $seeds[] = Seed::generate()->value();
        }

        $this->assertCount(100, array_unique($seeds));
    }

    public function test_it_reads_back_exactly_what_was_stored(): void
    {
        // A shuffle that cannot be recomputed is a pool that cannot be read back.
        $this->assertSame(self::LITERAL, Seed::fromString(self::LITERAL)->value());
    }

    public function test_it_rejects_a_value_that_is_not_a_seed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Seed::fromString('short');
    }

    public function test_tr_09_it_never_leaks_through_a_dump_a_string_or_json(): void
    {
        // A seed in a log is the whole board in a log.
        $seed = Seed::fromString(self::LITERAL);

        $this->assertStringNotContainsString(self::LITERAL, print_r($seed, true));
        $this->assertStringNotContainsString(self::LITERAL, var_export($seed, true));
        $this->assertStringNotContainsString(self::LITERAL, json_encode(['seed' => $seed]) ?: '');

        ob_start();
        var_dump($seed);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::LITERAL, $dump);
    }

    public function test_it_cannot_be_serialised_into_a_session_or_a_cache(): void
    {
        $this->expectException(Exception::class);

        serialize(Seed::fromString(self::LITERAL));
    }

    public function test_it_is_neither_stringable_nor_json_serialisable(): void
    {
        // Without this, `"seed: {$seed}"` leaks it and so does any payload that
        // happens to hold one.
        $this->assertFalse(method_exists(Seed::class, '__toString'));
        $this->assertFalse(is_a(Seed::class, \JsonSerializable::class, true));
    }

    public function test_nothing_built_from_a_seed_carries_it(): void
    {
        // The pool consumes the seed and keeps the order, not the secret.
        $pool = TilePool::fromDeck(TileDeck::standard(), Seed::fromString(self::LITERAL), 'election');

        $this->assertStringNotContainsString(self::LITERAL, print_r($pool, true));
        $this->assertStringNotContainsString(self::LITERAL, var_export($pool, true));
        $this->assertStringNotContainsString(self::LITERAL, json_encode($pool->project('faces_open', 'taken_stays_on_board')) ?: '');
    }
}
