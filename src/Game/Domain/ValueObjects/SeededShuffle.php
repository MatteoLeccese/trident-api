<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

/**
 * A deterministic permutation: the same seed and the same stream always produce
 * the same order, on any PHP build.
 *
 * Fisher-Yates over a byte stream of `hash('sha256', seed|stream|counter)`.
 * Never `shuffle()` and never `mt_srand()`: global RNG state is not a value, the
 * internal state of `mt_rand` is recoverable from a handful of outputs — and the
 * revealed prefix of the board is exactly that handful — and neither engine is
 * promised to be stable across PHP releases. A game that cannot be replayed
 * cannot be read back from storage (TR-09 keeps the seed on the server; nothing
 * keeps the order anywhere else).
 *
 * `$stream` separates two shuffles that share one seed, which is what lets a
 * game hold a single `games.shuffle_seed` and still give each stage a pool
 * shuffled independently of the other (TR-31). The caller passes the stage.
 */
final class SeededShuffle
{
    private const ALGORITHM = 'sha256';

    private const WORD_BYTES = 4;

    /** A sha256 block is 32 bytes, so eight words come out of one hash call. */
    private const WORDS_PER_BLOCK = 8;

    /** 2^32: the range of one word, and the modulus the sampling corrects for. */
    private const WORD_RANGE = 4294967296;

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function permute(array $items, Seed $seed, string $stream): array
    {
        $items = array_values($items);
        $word = 0;

        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = self::indexBelow($i + 1, $seed, $stream, $word);

            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    /**
     * A uniform index in `0..$bound - 1`.
     *
     * Rejection sampling and not a bare modulo: `$word % $bound` favours the low
     * indices whenever `$bound` does not divide 2^32, which biases the first
     * positions of the pool — the ones a game reaches soonest.
     */
    private static function indexBelow(int $bound, Seed $seed, string $stream, int &$word): int
    {
        $limit = intdiv(self::WORD_RANGE, $bound) * $bound;

        while (true) {
            $value = self::wordAt($word++, $seed, $stream);

            if ($value < $limit) {
                return $value % $bound;
            }
        }
    }

    /** Word number `$index` of the stream, as an unsigned 32-bit big-endian integer. */
    private static function wordAt(int $index, Seed $seed, string $stream): int
    {
        $block = hash(
            self::ALGORITHM,
            $seed->value().'|'.$stream.'|'.intdiv($index, self::WORDS_PER_BLOCK),
            binary: true,
        );

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($block, $index % self::WORDS_PER_BLOCK * self::WORD_BYTES, self::WORD_BYTES));

        return $unpacked[1];
    }
}
