<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

/**
 * The tiles a stage plays with.
 *
 * A tile is two independent faces, each 0-6, so the deck is every ordered pair:
 * 49 tiles, in which '01' and '10' are different tiles and each of the seven
 * doubles appears once. A pair of distinct faces therefore occupies two tiles and
 * a double occupies one, so drawing some double is one chance in seven.
 *
 * Built in a fixed order. Randomness belongs to the shuffle, so that a given
 * seed always produces the same game.
 */
final class TileDeck
{
    /**
     * @param  list<Tile>  $tiles
     */
    private function __construct(private readonly array $tiles) {}

    public static function standard(): self
    {
        $tiles = [];

        for ($left = 0; $left <= Tile::MAX_PIPS; $left++) {
            for ($right = 0; $right <= Tile::MAX_PIPS; $right++) {
                $tiles[] = Tile::of($left, $right);
            }
        }

        return new self($tiles);
    }

    /**
     * @return list<Tile>
     */
    public function tiles(): array
    {
        return $this->tiles;
    }

    public function count(): int
    {
        return count($this->tiles);
    }
}
