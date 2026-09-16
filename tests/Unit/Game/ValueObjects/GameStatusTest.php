<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\GameStatus;

final class GameStatusTest extends TestCase
{
    public function test_it_declares_the_four_states_a_game_can_be_in(): void
    {
        $this->assertSame(
            ['lobby', 'running', 'finished', 'abandoned'],
            GameStatus::ALL,
        );
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
    }

    public function test_it_is_a_constant_class_and_not_a_php_enum(): void
    {
        // Enum-persistence convention: VARCHAR in the database, validated in the
        // application. It keeps Postgres and SQLite interchangeable in the tests.
        $this->assertFalse(enum_exists(GameStatus::class));
    }
}
