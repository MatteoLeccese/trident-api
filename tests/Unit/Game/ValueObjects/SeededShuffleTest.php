<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\SeededShuffle;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TileDeck;

final class SeededShuffleTest extends TestCase
{
    private const LITERAL_SEED = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

    private function seed(): Seed
    {
        return Seed::fromString(self::LITERAL_SEED);
    }

    /**
     * @return list<string>
     */
    private function deckOrder(string $stream): array
    {
        return array_map(
            static fn (Tile $tile): string => $tile->value(),
            SeededShuffle::permute(TileDeck::standard()->tiles(), $this->seed(), $stream),
        );
    }

    public function test_a_literal_seed_produces_a_literal_order(): void
    {
        // The only test that can catch a silent change of algorithm. If it fails
        // and the change was deliberate, every game already on a table has a pool
        // that no longer reads back from its seed.
        $this->assertSame([
            '50', '05', '02', '15', '62', '32', '25', '42', '14', '36',
            '61', '11', '06', '35', '63', '23', '34', '40', '66', '65',
            '10', '46', '44', '51', '16', '55', '33', '12', '60', '52',
            '64', '22', '00', '43', '04', '13', '20', '03', '01', '31',
            '30', '21', '26', '53', '56', '24', '54', '41', '45',
        ], $this->deckOrder('election'));
    }

    public function test_the_same_seed_and_stream_always_produce_the_same_order(): void
    {
        $this->assertSame($this->deckOrder('election'), $this->deckOrder('election'));
    }

    public function test_a_second_stream_of_the_same_seed_produces_a_different_order(): void
    {
        // One seed per game, one pool per stage, shuffled independently.
        $this->assertNotSame($this->deckOrder('election'), $this->deckOrder('main'));
    }

    public function test_a_different_seed_produces_a_different_order(): void
    {
        $other = SeededShuffle::permute(TileDeck::standard()->tiles(), Seed::generate(), 'election');

        $this->assertNotSame(
            $this->deckOrder('election'),
            array_map(static fn (Tile $tile): string => $tile->value(), $other),
        );
    }

    public function test_it_is_a_permutation_and_loses_nothing(): void
    {
        $before = array_map(static fn (Tile $tile): string => $tile->value(), TileDeck::standard()->tiles());
        $after = $this->deckOrder('election');

        sort($before);
        sort($after);

        $this->assertSame($before, $after);
    }

    public function test_it_does_not_hand_back_the_order_it_was_given(): void
    {
        $this->assertNotSame(
            array_map(static fn (Tile $tile): string => $tile->value(), TileDeck::standard()->tiles()),
            $this->deckOrder('election'),
        );
    }

    public function test_it_reindexes_what_it_is_given(): void
    {
        // A list with holes in its keys comes back as a list, or position 1 stops
        // being the first tile.
        $gapped = array_filter([1, 2, 3, 4, 5], static fn (int $n): bool => $n !== 3);

        $permuted = SeededShuffle::permute(array_values($gapped), $this->seed(), 'election');

        $this->assertSame([0, 1, 2, 3], array_keys($permuted));
    }

    public function test_an_empty_list_and_a_single_item_survive(): void
    {
        $this->assertSame([], SeededShuffle::permute([], $this->seed(), 'election'));
        $this->assertSame(['only'], SeededShuffle::permute(['only'], $this->seed(), 'election'));
    }

    public function test_a_word_in_the_rejection_window_produces_no_index(): void
    {
        // 2^32 is not a multiple of 49, so its last 39 words would favour the
        // first 39 indices of the pool. They are rejected and another word is
        // drawn. The literal order above cannot show this: a window of 39 words
        // in 2^32 is never reached by the words that shuffle one deck.
        $this->assertSame(0, SeededShuffle::indexOfWord(0, 49));
        $this->assertSame(48, SeededShuffle::indexOfWord(48, 49));

        // 4294967257 is the largest multiple of 49 that fits in a word.
        $this->assertSame(48, SeededShuffle::indexOfWord(4294967256, 49));
        $this->assertNull(SeededShuffle::indexOfWord(4294967257, 49));
        $this->assertNull(SeededShuffle::indexOfWord(4294967295, 49));
    }

    public function test_a_bound_that_divides_the_word_range_rejects_nothing(): void
    {
        $this->assertSame(0, SeededShuffle::indexOfWord(0, 16));
        $this->assertSame(15, SeededShuffle::indexOfWord(4294967295, 16));
    }

    public function test_every_position_is_reachable(): void
    {
        // A biased draw would leave some index of a five-item list never used.
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $permuted = SeededShuffle::permute(['a', 'b', 'c', 'd', 'e'], Seed::generate(), 'election');
            $seen[array_search('a', $permuted, true)] = true;
        }

        ksort($seen);

        $this->assertSame([0, 1, 2, 3, 4], array_keys($seen));
    }
}
