<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * The tiles a stage plays with.
 *
 * `standard()` is the double-six set: two independent faces of 0 to
 * `STANDARD_MAX_FACE`, every ordered pair, so 49 tiles in which '01' and '10'
 * are different tiles and each of the seven doubles appears once. A pair of
 * distinct faces therefore occupies two tiles and a double occupies one, so
 * drawing some double is one chance in seven.
 *
 * Built in a fixed order. Randomness belongs to the shuffle, so that a given
 * seed always produces the same game.
 *
 * `standard()` is what `trident.v1` plays with (TR-01) and it is not the only
 * deck this class can hold: `RuleSet::deck()` returns a `TileDeck`, so a ruleset
 * that plays with four tiles, or with a face of nine, needs a way to build one,
 * and a class whose only constructor is the one deck of the one ruleset is a
 * rule baked into a framework value object.
 */
final class TileDeck
{
    /** The highest face of the double-six set, which is what `standard()` builds. */
    public const STANDARD_MAX_FACE = 6;

    /**
     * @param  list<Tile>  $tiles
     */
    private function __construct(private readonly array $tiles) {}

    public static function standard(): self
    {
        $tiles = [];

        for ($left = 0; $left <= self::STANDARD_MAX_FACE; $left++) {
            for ($right = 0; $right <= self::STANDARD_MAX_FACE; $right++) {
                $tiles[] = Tile::of($left, $right);
            }
        }

        return new self($tiles);
    }

    /**
     * Any deck a ruleset wants, in the order it gives.
     *
     * Duplicates are allowed: a draw is identified by `(stage, position)` and
     * never by its tile (TR-04), so a deck holding the same tile twice is two
     * positions and not a collision.
     *
     * An empty deck is refused. No draw could ever happen in a stage with no
     * positions, so no `Outcome` could ever move the game on: it would hang a
     * table rather than end one, and a loud failure where the deck is declared
     * is the only place the mistake is still legible.
     */
    public static function of(Tile ...$tiles): self
    {
        if ($tiles === []) {
            throw new InvalidArgumentException('A deck holds at least one tile.');
        }

        return new self(array_values($tiles));
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
