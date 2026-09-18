<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TileDeck;

final class TileDeckTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function values(): array
    {
        return array_map(static fn (Tile $t): string => $t->value(), TileDeck::standard()->tiles());
    }

    public function test_the_deck_is_every_ordered_pair_of_faces(): void
    {
        $this->assertCount(49, $this->values());
    }

    public function test_a_tile_and_its_mirror_are_different_tiles(): void
    {
        $values = $this->values();

        $this->assertContains('01', $values);
        $this->assertContains('10', $values);
    }

    public function test_the_deck_holds_the_seven_doubles_once_each(): void
    {
        $values = $this->values();

        foreach (['00', '11', '22', '33', '44', '55', '66'] as $double) {
            $this->assertSame(1, count(array_keys($values, $double, true)), "{$double} is not present exactly once.");
        }
    }

    public function test_the_deck_holds_no_duplicates(): void
    {
        $values = $this->values();

        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function test_every_face_from_zero_to_six_appears_on_both_sides(): void
    {
        $values = $this->values();

        for ($face = 0; $face <= 6; $face++) {
            $this->assertContains("{$face}0", $values);
            $this->assertContains("0{$face}", $values);
        }
    }

    public function test_the_deck_is_built_in_a_fixed_order(): void
    {
        $this->assertSame($this->values(), $this->values());
    }

    public function test_a_ruleset_may_build_any_deck_it_wants(): void
    {
        // `RuleSet::deck()` returns a TileDeck, so a stage of four tiles needs a
        // way to build one: a class whose only constructor is the 49 of TR-01
        // would be a rule baked into a framework value object.
        $deck = TileDeck::of(Tile::of(0, 0), Tile::of(1, 1), Tile::of(2, 2), Tile::of(3, 3));

        $this->assertSame(4, $deck->count());
        $this->assertSame(
            ['00', '11', '22', '33'],
            array_map(static fn (Tile $tile): string => $tile->value(), $deck->tiles()),
        );
    }

    public function test_a_deck_may_hold_faces_the_standard_set_does_not(): void
    {
        // The size of a deck and its alphabet are both the ruleset's: a
        // double-nine variant is a deck of its own and not an edit of `Tile`.
        $deck = TileDeck::of(Tile::of(9, 9), Tile::of(7, 9));

        $this->assertSame(
            ['99', '79'],
            array_map(static fn (Tile $tile): string => $tile->value(), $deck->tiles()),
        );
    }

    public function test_a_deck_may_hold_the_same_tile_twice(): void
    {
        // A draw is identified by (stage, position) and never by its tile (TR-04),
        // so two positions holding one tile is not a collision.
        $this->assertSame(2, TileDeck::of(Tile::of(3, 3), Tile::of(3, 3))->count());
    }

    public function test_an_empty_deck_is_refused(): void
    {
        // No draw could happen in a stage with no positions, so no Outcome could
        // move the game on: it would hang a table rather than end one.
        $this->expectException(InvalidArgumentException::class);

        TileDeck::of();
    }
}
