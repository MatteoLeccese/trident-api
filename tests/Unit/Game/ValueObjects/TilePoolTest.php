<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Exceptions\PoolPositionAlreadyTakenException;
use Src\Game\Domain\Exceptions\PoolPositionNotInPoolException;
use Src\Game\Domain\Rules\BoardPresence;
use Src\Game\Domain\Rules\Visibility;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TileDeck;
use Src\Game\Domain\ValueObjects\TilePool;

final class TilePoolTest extends TestCase
{
    private const LITERAL_SEED = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

    private const HIDDEN = Visibility::FACES_HIDDEN_UNTIL_TAKEN;

    private const STAYS = BoardPresence::TAKEN_STAYS_ON_BOARD;

    private const LEAVES = BoardPresence::TAKEN_LEAVES_BOARD;

    private function pool(string $stream = 'election'): TilePool
    {
        return TilePool::fromDeck(TileDeck::standard(), Seed::fromString(self::LITERAL_SEED), $stream);
    }

    /**
     * The projection of a hidden board that keeps its taken positions, which is
     * what `trident.v1` asks for in the election (TR-07, TR-52).
     *
     * @return list<array{position: int, tile: string|null, taken: bool, seat: int|null, on_board: bool}>
     */
    private function hidden(TilePool $pool): array
    {
        return $pool->project(self::HIDDEN, self::STAYS);
    }

    private function seat(int $number): SeatNumber
    {
        return SeatNumber::fromInt($number);
    }

    /**
     * @return list<string>
     */
    private function faces(TilePool $pool): array
    {
        return array_map(static fn (Tile $tile): string => $tile->value(), $pool->tiles());
    }

    /**
     * The takers as plain integers, keyed by position.
     *
     * The pool exposes the takers as a map and never one position at a time, so
     * a test that wants one reads it out of the map like the repository does.
     *
     * @return array<int, int>
     */
    private function takerNumbers(TilePool $pool): array
    {
        return array_map(static fn (SeatNumber $seat): int => $seat->value(), $pool->takers());
    }

    public function test_a_pool_holds_one_position_per_tile_of_the_deck(): void
    {
        $pool = $this->pool();

        $this->assertSame(49, $pool->count());
        $this->assertSame(49, $pool->remaining());
        $this->assertTrue($pool->has(PoolPosition::fromInt(49)));
        $this->assertFalse($pool->has(PoolPosition::fromInt(50)));
    }

    public function test_the_pool_of_a_standard_deck_holds_exactly_one_double_three(): void
    {
        // The whole arithmetic of the election hangs off this one tile.
        $faces = $this->faces($this->pool());

        $this->assertCount(1, array_keys($faces, '33', true));
    }

    public function test_each_stage_materialises_its_own_order(): void
    {
        $this->assertNotSame($this->faces($this->pool('election')), $this->faces($this->pool('main')));
    }

    public function test_a_position_the_pool_does_not_hold_is_refused(): void
    {
        $this->expectException(PoolPositionNotInPoolException::class);

        $this->pool()->at(PoolPosition::fromInt(50));
    }

    public function test_it_knows_the_face_of_every_position_before_anybody_takes_it(): void
    {
        // The server holds every face; hiding happens in the projection.
        $pool = $this->pool();

        $this->assertSame('50', $pool->at(PoolPosition::first())->value());
        $this->assertFalse($pool->isTaken(PoolPosition::first()));
    }

    public function test_an_untaken_position_projects_no_face(): void
    {
        $projection = $this->hidden($this->pool());

        $this->assertSame(
            ['position' => 1, 'tile' => null, 'taken' => false, 'seat' => null, 'on_board' => true],
            $projection[0],
        );

        foreach ($projection as $entry) {
            $this->assertNull($entry['tile']);
            $this->assertFalse($entry['taken']);
            $this->assertNull($entry['seat'], 'Nobody took it, so nobody is named.');
            $this->assertTrue($entry['on_board'], 'An untaken position is always on the board.');
        }
    }

    public function test_a_taken_position_projects_its_face_and_the_rest_stay_hidden(): void
    {
        $projection = $this->hidden($this->pool()->take(PoolPosition::fromInt(3), $this->seat(2)));

        $this->assertSame(
            ['position' => 3, 'tile' => '02', 'taken' => true, 'seat' => 2, 'on_board' => true],
            $projection[2],
        );
        $this->assertNull($projection[1]['tile']);
    }

    public function test_an_open_stage_projects_every_face(): void
    {
        $projection = $this->pool()->project(Visibility::FACES_OPEN, self::STAYS);

        $this->assertSame(
            ['position' => 1, 'tile' => '50', 'taken' => false, 'seat' => null, 'on_board' => true],
            $projection[0],
        );
    }

    public function test_an_unrecognised_visibility_hides(): void
    {
        // Fail closed: a visibility nobody declared must not open the board.
        $projection = $this->pool()->project('', self::STAYS);

        $this->assertNull($projection[0]['tile']);
    }

    public function test_the_projection_carries_an_explicit_null_and_never_an_absent_key(): void
    {
        $entry = $this->hidden($this->pool())[0];

        $this->assertArrayHasKey('tile', $entry);
        $this->assertArrayHasKey('seat', $entry);
        // The order is pinned as well as the keys: the two delivery paths are
        // compared byte for byte, so a reordering here would part them.
        $this->assertSame(['position', 'tile', 'taken', 'seat', 'on_board'], array_keys($entry));
    }

    public function test_the_projection_never_carries_the_seed(): void
    {
        $encoded = json_encode($this->hidden($this->pool())) ?: '';

        $this->assertStringNotContainsString(self::LITERAL_SEED, $encoded);

        foreach (['seed', 'shuffle', 'token', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    public function test_taking_a_position_twice_is_refused(): void
    {
        $pool = $this->pool()->take(PoolPosition::fromInt(3), $this->seat(2));

        $this->expectException(PoolPositionAlreadyTakenException::class);

        // Another seat cannot take it either: the refusal is about the position.
        $pool->take(PoolPosition::fromInt(3), $this->seat(3));
    }

    public function test_taking_a_position_the_pool_does_not_hold_is_refused(): void
    {
        $this->expectException(PoolPositionNotInPoolException::class);

        $this->pool()->take(PoolPosition::fromInt(50), SeatNumber::first());
    }

    public function test_tr_08_a_taken_position_is_marked_and_never_removed(): void
    {
        // Removing it would renumber every position after it, and a stale phone
        // touching position 7 would draw a different tile.
        $before = $this->pool();
        $after = $before->take(PoolPosition::first(), SeatNumber::first());

        $this->assertSame(49, $after->count());
        $this->assertSame($this->faces($before), $this->faces($after));
        $this->assertSame('05', $after->at(PoolPosition::fromInt(2))->value());

        // Its face is open in the one projection both clients read (TR-06).
        $this->assertSame('50', $this->hidden($after)[0]['tile']);
        $this->assertTrue($this->hidden($after)[0]['taken']);
    }

    public function test_taking_leaves_the_pool_it_came_from_untouched(): void
    {
        $before = $this->pool();
        $after = $before->take(PoolPosition::first(), SeatNumber::first());

        $this->assertFalse($before->isTaken(PoolPosition::first()));
        $this->assertSame([], $before->takers());
        $this->assertTrue($after->isTaken(PoolPosition::first()));
        $this->assertSame([1 => 1], $this->takerNumbers($after));
    }

    public function test_it_knows_what_remains_and_when_it_is_exhausted(): void
    {
        $pool = $this->pool();

        $this->assertFalse($pool->isExhausted());

        for ($position = 1; $position <= 49; $position++) {
            $pool = $pool->take(PoolPosition::fromInt($position), SeatNumber::first());
        }

        $this->assertSame(0, $pool->remaining());
        $this->assertTrue($pool->isExhausted());
    }

    public function test_it_round_trips_through_persistence(): void
    {
        // Taken out of order on purpose: the stored positions are ascending, so
        // two games in the same state persist the same bytes whatever order the
        // table played them in.
        $pool = $this->pool()
            ->take(PoolPosition::fromInt(9), $this->seat(3))
            ->take(PoolPosition::fromInt(2), $this->seat(1));

        $restored = TilePool::reconstitute($pool->tiles(), $pool->takers());

        $this->assertSame([2, 9], array_keys($pool->takers()));
        $this->assertSame([2, 9], array_keys($restored->takers()));
        $this->assertSame([1, 3], array_map(
            static fn (SeatNumber $seat): int => $seat->value(),
            array_values($restored->takers()),
        ));
        $this->assertSame($this->hidden($pool), $this->hidden($restored));
    }

    public function test_it_refuses_to_be_restored_with_a_position_it_does_not_hold(): void
    {
        $this->expectException(PoolPositionNotInPoolException::class);

        TilePool::reconstitute([Tile::fromString('33')], [2 => SeatNumber::first()]);
    }

    public function test_it_refuses_to_be_restored_with_a_position_below_the_first(): void
    {
        // A stored blob is checked at both ends: a position of 0 would mark
        // nothing, leaving `remaining()` one short of the truth for good and a
        // stage that can never report itself exhausted.
        $this->expectException(PoolPositionNotInPoolException::class);

        TilePool::reconstitute([Tile::fromString('33')], [0 => SeatNumber::first()]);
    }

    public function test_it_refuses_to_be_restored_with_a_negative_position(): void
    {
        $this->expectException(PoolPositionNotInPoolException::class);

        TilePool::reconstitute([Tile::fromString('33')], [-1 => SeatNumber::first()]);
    }

    public function test_a_pool_of_four_tiles_is_as_valid_as_a_pool_of_forty_nine(): void
    {
        // Nothing may assume 49 because the standard deck happens to hold 49.
        $tiles = [Tile::fromString('00'), Tile::fromString('11'), Tile::fromString('22'), Tile::fromString('33')];

        $pool = TilePool::reconstitute($tiles, []);

        $this->assertSame(4, $pool->count());
        $this->assertFalse($pool->has(PoolPosition::fromInt(5)));
        $this->assertCount(4, $this->hidden($pool));
    }

    public function test_a_taken_position_projects_the_seat_that_took_it(): void
    {
        // The whole value of the television on the table: from a sofa you watch
        // the board fill up and see WHO filled it, without counting a pip.
        $pool = $this->pool()
            ->take(PoolPosition::first(), $this->seat(1))
            ->take(PoolPosition::fromInt(2), $this->seat(3));

        $projection = $this->hidden($pool);

        $this->assertSame(1, $projection[0]['seat']);
        $this->assertSame(3, $projection[1]['seat']);
        $this->assertNull($projection[2]['seat'], 'An untaken position projects null and never an absent key.');
    }

    public function test_the_taker_is_kept_per_position_and_not_per_seat(): void
    {
        // One seat takes two positions and both remember it: the map is keyed by
        // position, so a second draw by the same seat cannot overwrite the first.
        $pool = $this->pool()
            ->take(PoolPosition::first(), $this->seat(2))
            ->take(PoolPosition::fromInt(5), $this->seat(2));

        $this->assertSame([1 => 2, 5 => 2], $this->takerNumbers($pool));
        $this->assertSame(47, $pool->remaining());
    }

    public function test_the_takers_are_ascending_by_position_whatever_order_they_were_played_in(): void
    {
        // Two games in the same state persist the same bytes, which is what the
        // repository writes down.
        $pool = $this->pool()
            ->take(PoolPosition::fromInt(9), $this->seat(1))
            ->take(PoolPosition::first(), $this->seat(2))
            ->take(PoolPosition::fromInt(4), $this->seat(3));

        $this->assertSame([1, 4, 9], array_keys($pool->takers()));
    }

    public function test_a_board_that_keeps_its_taken_positions_and_one_that_clears_them_project_differently(): void
    {
        // TR-52 is a setting and not a rule, so it changes what a screen draws
        // and nothing else: the same pool, the same faces, the same takers, and
        // one flag apart.
        $pool = $this->pool()->take(PoolPosition::first(), $this->seat(1));

        $stays = $pool->project(self::HIDDEN, self::STAYS);
        $leaves = $pool->project(self::HIDDEN, self::LEAVES);

        $this->assertNotSame($stays, $leaves);
        $this->assertTrue($stays[0]['on_board']);
        $this->assertFalse($leaves[0]['on_board']);

        // Everything else is untouched, including the face and the taker: what
        // left the board did not leave the record.
        $this->assertSame($stays[0]['tile'], $leaves[0]['tile']);
        $this->assertSame($stays[0]['seat'], $leaves[0]['seat']);
        $this->assertTrue($leaves[0]['taken']);

        // And a position nobody has taken is on the board under both values, so
        // the geometry of the grid is the same either way.
        $this->assertTrue($stays[1]['on_board']);
        $this->assertTrue($leaves[1]['on_board']);
    }

    public function test_an_unrecognised_board_presence_keeps_the_board(): void
    {
        // The opposite direction from the visibility above, and deliberately:
        // blanking a board on a typo hides exactly what the screen exists to
        // show, while a taken face is public already.
        $pool = $this->pool()->take(PoolPosition::first(), $this->seat(1));

        foreach (['', 'TAKEN_LEAVES_BOARD', 'taken_leaves_board ', 'remove'] as $presence) {
            $this->assertTrue(
                $pool->project(self::HIDDEN, $presence)[0]['on_board'],
                "'{$presence}' cleared the board.",
            );
        }
    }

    public function test_the_projection_never_carries_the_taker_of_a_position_nobody_took(): void
    {
        $pool = TilePool::reconstitute([Tile::fromString('00'), Tile::fromString('11')], [2 => $this->seat(5)]);

        $projection = $this->hidden($pool);

        $this->assertNull($projection[0]['seat']);
        $this->assertFalse($projection[0]['taken']);
        $this->assertSame(5, $projection[1]['seat']);
        $this->assertTrue($projection[1]['taken']);
    }
}
