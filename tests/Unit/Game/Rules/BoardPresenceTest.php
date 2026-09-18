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
 * The closed set that answers whether a taken position stays on the board, and
 * the pool that obeys it.
 *
 * It is the projection's answer to a setting the table wrote (TR-52), resolved
 * before the wire so that no client has to name the setting, the stage or the
 * rule behind it.
 */
final class BoardPresenceTest extends TestCase
{
    private function pool(): TilePool
    {
        return TilePool::fromDeck(
            TileDeck::standard(),
            Seed::fromString('0123456789abcdefghijklmnopqrstuvwxyzABCDEFG'),
            'election',
        );
    }

    private function taken(): TilePool
    {
        return $this->pool()->take(PoolPosition::first(), SeatNumber::first());
    }

    public function test_it_declares_exactly_two_modes(): void
    {
        $this->assertSame(['taken_stays_on_board', 'taken_leaves_board'], BoardPresence::ALL);
    }

    public function test_it_recognises_its_own_values_and_nothing_else(): void
    {
        foreach (BoardPresence::ALL as $presence) {
            $this->assertTrue(BoardPresence::isValid($presence));
        }

        $this->assertFalse(BoardPresence::isValid('taken_leaves_board '));
        $this->assertFalse(BoardPresence::isValid('remove'));
        $this->assertFalse(BoardPresence::isValid(''));
    }

    public function test_it_is_never_a_boolean(): void
    {
        // The same argument as `Visibility`: a boolean cannot express a third
        // board mode without changing every signature that carries it.
        $this->assertFalse(BoardPresence::isValid('1'));
        $this->assertFalse(BoardPresence::isValid('true'));
    }

    public function test_a_taken_position_stays_on_the_board_under_one_mode_and_leaves_under_the_other(): void
    {
        $pool = $this->taken();

        $this->assertTrue(
            $pool->project(Visibility::FACES_HIDDEN_UNTIL_TAKEN, BoardPresence::TAKEN_STAYS_ON_BOARD)[0]['on_board'],
        );
        $this->assertFalse(
            $pool->project(Visibility::FACES_HIDDEN_UNTIL_TAKEN, BoardPresence::TAKEN_LEAVES_BOARD)[0]['on_board'],
        );
    }

    public function test_it_is_orthogonal_to_the_visibility(): void
    {
        // Four combinations, all four reachable: a stage may open its faces and
        // clear its board at once, which is the pair `trident.v1` never asks for
        // and the hostile double does.
        $pool = $this->taken();
        $seen = [];

        foreach (Visibility::ALL as $visibility) {
            foreach (BoardPresence::ALL as $presence) {
                $entry = $pool->project($visibility, $presence);

                $seen[] = [$entry[1]['tile'] !== null, $entry[0]['on_board']];
            }
        }

        $this->assertSame([[false, true], [false, false], [true, true], [true, false]], $seen);
    }

    public function test_an_unrecognised_mode_keeps_the_board(): void
    {
        // The opposite direction from the visibility, and deliberately: a typo
        // that blanked the board would hide what the screen exists to show, while
        // a taken face is public to everybody already (TR-08).
        foreach (['', 'TAKEN_LEAVES_BOARD', 'taken_leaves_board ', 'remove', 'anything'] as $presence) {
            $this->assertTrue(
                $this->taken()->project(Visibility::FACES_HIDDEN_UNTIL_TAKEN, $presence)[0]['on_board'],
                "'{$presence}' cleared the board.",
            );
        }
    }

    public function test_it_changes_nothing_a_position_that_nobody_has_taken(): void
    {
        // The grid keeps its geometry: only a taken position can leave the board.
        foreach (BoardPresence::ALL as $presence) {
            $projection = $this->pool()->project(Visibility::FACES_HIDDEN_UNTIL_TAKEN, $presence);

            $this->assertSame(
                [],
                array_filter($projection, static fn (array $entry): bool => $entry['on_board'] === false),
                "'{$presence}' took an untaken position off the board.",
            );
        }
    }
}
