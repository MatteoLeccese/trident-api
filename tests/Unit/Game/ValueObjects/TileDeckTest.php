<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

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
}
