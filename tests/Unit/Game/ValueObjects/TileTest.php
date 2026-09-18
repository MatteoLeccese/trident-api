<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\Tile;

final class TileTest extends TestCase
{
    public function test_a_tile_is_two_faces(): void
    {
        $tile = Tile::fromString('36');

        $this->assertSame(3, $tile->left());
        $this->assertSame(6, $tile->right());
        $this->assertSame('36', $tile->value());
    }

    public function test_the_double_blank_is_a_tile(): void
    {
        // '00' is falsy in several languages. It has to survive being a value.
        $tile = Tile::fromString('00');

        $this->assertSame(0, $tile->left());
        $this->assertSame(0, $tile->right());
        $this->assertSame('00', $tile->value());
    }

    public function test_a_face_may_be_any_single_digit(): void
    {
        // The alphabet belongs to the wire format of TR-02 and not to a game:
        // which faces are dealt is decided by the deck a ruleset returns, so a
        // double-nine set is a deck this class has to be able to hold.
        $this->assertSame('99', Tile::fromString('99')->value());
        $this->assertSame(7, Tile::fromString('79')->left());
        $this->assertSame(9, Tile::fromString('79')->right());
        $this->assertTrue(Tile::fromString('88')->isDouble());
    }

    public function test_it_knows_its_pip_total(): void
    {
        $this->assertSame(9, Tile::fromString('36')->total());
        $this->assertSame(0, Tile::fromString('00')->total());
        $this->assertSame(12, Tile::fromString('66')->total());
    }

    public function test_it_knows_whether_it_is_a_double(): void
    {
        $this->assertTrue(Tile::fromString('44')->isDouble());
        $this->assertFalse(Tile::fromString('45')->isDouble());
    }

    public function test_two_tiles_with_the_same_faces_are_equal(): void
    {
        $this->assertTrue(Tile::fromString('36')->equals(Tile::fromString('36')));
        $this->assertFalse(Tile::fromString('36')->equals(Tile::fromString('63')));
    }

    public function test_it_serialises_as_its_two_characters(): void
    {
        $this->assertSame('"36"', json_encode(Tile::fromString('36')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTiles(): array
    {
        return [
            'empty' => [''],
            'one digit' => ['3'],
            'three digits' => ['366'],
            'a letter' => ['3a'],
            'negative' => ['-3'],
            'with a space' => ['3 6'],
            'trailing newline' => ["36\n"],
        ];
    }

    #[DataProvider('invalidTiles')]
    public function test_it_rejects_anything_that_is_not_a_domino(string $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);

        Tile::fromString($invalid);
    }
}
