<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\GameStatus;

final class GameStatusTest extends TestCase
{
    public function test_it_declares_the_states_a_game_can_be_in(): void
    {
        $this->assertSame(
            ['lobby', 'running', 'awaiting_choice', 'finished', 'abandoned'],
            GameStatus::ALL,
        );
    }

    public function test_every_status_fits_the_column_that_stores_it(): void
    {
        // `games.status` is VARCHAR(16): a status is stored, never enumerated by
        // the database.
        foreach (GameStatus::ALL as $status) {
            $this->assertLessThanOrEqual(16, strlen($status), "'{$status}' does not fit games.status.");
        }
    }

    public function test_it_validates_a_value(): void
    {
        $this->assertTrue(GameStatus::isValid('lobby'));
        $this->assertFalse(GameStatus::isValid('playing'));
        $this->assertFalse(GameStatus::isValid('LOBBY'));
        $this->assertFalse(GameStatus::isValid(''));
    }

    public function test_finished_and_abandoned_are_terminal(): void
    {
        // A terminal state closes the channel and releases the game's code.
        $this->assertTrue(GameStatus::isTerminal(GameStatus::FINISHED));
        $this->assertTrue(GameStatus::isTerminal(GameStatus::ABANDONED));
        $this->assertFalse(GameStatus::isTerminal(GameStatus::LOBBY));
        $this->assertFalse(GameStatus::isTerminal(GameStatus::RUNNING));

        // A game parked on a PendingChoice is live: the channel is open, the code
        // still works, and one seat owes an answer.
        $this->assertFalse(GameStatus::isTerminal(GameStatus::AWAITING_CHOICE));
    }

    public function test_it_is_a_constant_class_and_not_a_php_enum(): void
    {
        // Enum-persistence convention: VARCHAR in the database, validated in the
        // application. It keeps Postgres and SQLite interchangeable in the tests.
        $this->assertFalse(enum_exists(GameStatus::class));
    }
}
