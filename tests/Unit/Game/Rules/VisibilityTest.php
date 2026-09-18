<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\BoardPresence;
use Src\Game\Domain\Rules\Visibility;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\TileDeck;
use Src\Game\Domain\ValueObjects\TilePool;

/**
 * The closed set that answers whether untaken faces are published, and the pool
 * that obeys it.
 */
final class VisibilityTest extends TestCase
{
    private function pool(): TilePool
    {
        return TilePool::fromDeck(
            TileDeck::standard(),
            Seed::fromString('0123456789abcdefghijklmnopqrstuvwxyzABCDEFG'),
            'election',
        );
    }

    public function test_it_declares_exactly_two_modes(): void
    {
        $this->assertSame(['faces_hidden_until_taken', 'faces_open'], Visibility::ALL);
    }

    public function test_it_recognises_its_own_values_and_nothing_else(): void
    {
        foreach (Visibility::ALL as $visibility) {
            $this->assertTrue(Visibility::isValid($visibility));
        }

        $this->assertFalse(Visibility::isValid('faces_open '));
        $this->assertFalse(Visibility::isValid('open'));
        $this->assertFalse(Visibility::isValid(''));
    }

    public function test_it_is_never_a_boolean(): void
    {
        // A boolean cannot express a third mode without changing every signature
        // that carries it, and a ruleset that opens the board midway is that mode.
        $this->assertFalse(Visibility::isValid('1'));
        $this->assertFalse(Visibility::isValid('true'));
    }

    public function test_the_pool_publishes_every_face_only_when_the_stage_is_open(): void
    {
        $open = $this->pool()->project(Visibility::FACES_OPEN, BoardPresence::TAKEN_STAYS_ON_BOARD);

        $this->assertSame(
            ['position' => 1, 'tile' => '50', 'taken' => false, 'seat' => null, 'on_board' => true],
            $open[0],
        );
    }

    public function test_the_pool_hides_untaken_faces_under_the_other_mode(): void
    {
        $hidden = $this->pool()->project(Visibility::FACES_HIDDEN_UNTIL_TAKEN, BoardPresence::TAKEN_STAYS_ON_BOARD);

        $this->assertSame(
            ['position' => 1, 'tile' => null, 'taken' => false, 'seat' => null, 'on_board' => true],
            $hidden[0],
        );
    }

    public function test_an_unrecognised_visibility_hides_rather_than_opening_the_board(): void
    {
        // Fail-closed: a typo in a future ruleset must not publish 49 faces to a
        // television.
        foreach (['', 'FACES_OPEN', 'faces_open ', 'anything'] as $visibility) {
            $projection = $this->pool()->project($visibility, BoardPresence::TAKEN_STAYS_ON_BOARD);

            $this->assertNull($projection[0]['tile'], "'{$visibility}' opened the board.");
        }
    }

    public function test_a_taken_face_is_published_under_both_modes(): void
    {
        $pool = $this->pool()->take(PoolPosition::first(), SeatNumber::first());

        foreach (Visibility::ALL as $visibility) {
            $this->assertSame('50', $pool->project($visibility, BoardPresence::TAKEN_STAYS_ON_BOARD)[0]['tile']);
        }
    }
}
