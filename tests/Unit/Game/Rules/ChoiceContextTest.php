<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\ChoiceContext;
use Src\Game\Domain\Rules\PendingChoice;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\Draw;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TilePool;

/**
 * What a rule is told when the seat its choice named has answered.
 */
final class ChoiceContextTest extends TestCase
{
    public function test_it_carries_the_answer_and_the_question_it_answers(): void
    {
        $context = $this->context();

        $this->assertSame(2, $context->seat()->value());
        $this->assertSame('choice.left', $context->option());
        $this->assertTrue($context->choice()->allows($context->option()));
        $this->assertSame('alpha', $context->stage()->value());
        $this->assertSame(1, $context->turnNumber());
        $this->assertSame(3, $context->seats()->count());
        $this->assertSame(1, $context->state()->version());
        $this->assertSame([], $context->roomConfig()->toArray());
    }

    public function test_the_pool_it_carries_has_the_draw_that_raised_the_choice_applied(): void
    {
        // Unlike DrawContext::poolBefore(): by the time an answer arrives the
        // position has been taken.
        $context = $this->context();

        $this->assertTrue($context->pool()->isTaken(PoolPosition::first()));
        $this->assertFalse($context->priorDraws()->isEmpty());
    }

    public function test_an_answer_from_another_seat_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->context(seat: SeatNumber::fromInt(3));
    }

    public function test_an_answer_that_was_not_offered_is_refused(): void
    {
        // A ruleset may therefore branch on the option with a `match` whose
        // default is an incident rather than a guess.
        $this->expectException(InvalidArgumentException::class);

        $this->context(option: 'choice.middle');
    }

    public function test_a_choice_raised_before_the_first_draw_is_legal(): void
    {
        $this->assertSame(0, $this->context(turnNumber: 0)->turnNumber());

        $this->expectException(InvalidArgumentException::class);
        $this->context(turnNumber: -1);
    }

    private function context(?SeatNumber $seat = null, string $option = 'choice.left', int $turnNumber = 1): ChoiceContext
    {
        $answerer = SeatNumber::fromInt(2);
        $pool = TilePool::reconstitute([Tile::of(0, 1), Tile::of(1, 2)], [1 => SeatNumber::first()]);

        return ChoiceContext::of(
            $seat ?? $answerer,
            $option,
            PendingChoice::of($answerer, 'choice.prompt', ['choice.left', 'choice.right']),
            $this->roster(),
            DrawLog::empty()->append(Draw::of('alpha', $answerer, PoolPosition::first(), Tile::of(0, 1))),
            $pool,
            $turnNumber,
            StageId::fromString('alpha'),
            RuleState::initial(1),
            RoomConfig::empty(),
        );
    }

    private function roster(): SeatRoster
    {
        $seats = [];

        for ($number = 1; $number <= 3; $number++) {
            $seats[] = Seat::of(SeatNumber::fromInt($number), Nickname::fromString("Player {$number}"));
        }

        return SeatRoster::fromSeats($seats);
    }
}
