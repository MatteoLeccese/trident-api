<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\PendingChoice;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * The one member of `Outcome` that parks a game instead of moving it on.
 */
final class PendingChoiceTest extends TestCase
{
    public function test_it_names_one_seat_a_question_and_the_answers_that_seat_may_give(): void
    {
        $choice = PendingChoice::of(SeatNumber::fromInt(3), 'choice.point_at_someone', ['choice.left', 'choice.right']);

        $this->assertSame(3, $choice->seat()->value());
        $this->assertSame('choice.point_at_someone', $choice->promptKey());
        $this->assertSame(['choice.left', 'choice.right'], $choice->options());
    }

    public function test_it_answers_whether_an_option_was_offered(): void
    {
        $choice = PendingChoice::of(SeatNumber::first(), 'choice.prompt', ['a', 'b']);

        $this->assertTrue($choice->allows('a'));
        $this->assertFalse($choice->allows('c'));
        $this->assertFalse($choice->allows(''));
    }

    public function test_a_choice_offers_at_least_two_distinct_options(): void
    {
        // One answer is not a choice, and the same answer twice is one answer
        // written twice.
        foreach ([[], ['a'], ['a', 'a']] as $options) {
            try {
                PendingChoice::of(SeatNumber::first(), 'choice.prompt', $options);
                self::fail('A choice was built from '.count($options).' usable options.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_prompt_and_the_options_are_message_keys(): void
    {
        // Copy the application ships, never text the table wrote: a question the
        // framework parks a game on is not painted with untrusted input.
        foreach (['', 'Choose one', 'choice..prompt', 'Choice.Prompt', "choice.prompt\n"] as $key) {
            try {
                PendingChoice::of(SeatNumber::first(), $key, ['a', 'b']);
                self::fail("'{$key}' was accepted as a prompt key.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        PendingChoice::of(SeatNumber::first(), 'choice.prompt', ['a', 'Point at Ana']);
    }

    public function test_it_carries_the_same_bytes_to_both_clients(): void
    {
        $choice = PendingChoice::of(SeatNumber::fromInt(2), 'choice.prompt', ['choice.a', 'choice.b']);

        $this->assertSame(
            ['seat' => 2, 'prompt_key' => 'choice.prompt', 'options' => ['choice.a', 'choice.b']],
            $choice->toArray(),
        );
        $this->assertSame($choice->toArray(), json_decode((string) json_encode($choice), true));
    }

    public function test_a_choice_always_names_a_seat(): void
    {
        // A null seat means "the whole table" in Effect::challenge, and a question
        // the whole table answers has no single answer to wait for.
        $this->assertFalse(
            (new \ReflectionMethod(PendingChoice::class, 'of'))->getParameters()[0]->allowsNull(),
        );
    }
}
