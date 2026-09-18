<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\Rules\StageSequence;

final class StageSequenceTest extends TestCase
{
    private function twoStages(): StageSequence
    {
        return StageSequence::of(StageId::fromString('election'), 'Election')
            ->then(StageId::fromString('main'), 'Main game');
    }

    public function test_it_keeps_the_order_the_ruleset_declared(): void
    {
        $ids = array_map(static fn (StageId $stage): string => $stage->value(), $this->twoStages()->all());

        $this->assertSame(['election', 'main'], $ids);
        $this->assertSame('election', $this->twoStages()->first()->value());
    }

    public function test_it_carries_the_label_a_screen_paints(): void
    {
        $this->assertSame('Main game', $this->twoStages()->labelOf(StageId::fromString('main')));
    }

    public function test_it_projects_objects_with_an_explicit_id(): void
    {
        $this->assertSame(
            [
                ['id' => 'election', 'label' => 'Election'],
                ['id' => 'main', 'label' => 'Main game'],
            ],
            $this->twoStages()->toArray(),
        );
    }

    public function test_adding_a_stage_leaves_the_sequence_it_came_from_alone(): void
    {
        $one = StageSequence::of(StageId::fromString('election'), 'Election');

        $one->then(StageId::fromString('main'), 'Main game');

        $this->assertCount(1, $one->all());
    }

    public function test_it_knows_which_stages_it_holds(): void
    {
        $this->assertTrue($this->twoStages()->has(StageId::fromString('main')));
        $this->assertFalse($this->twoStages()->has(StageId::fromString('bonus')));
    }

    public function test_a_stage_it_does_not_hold_has_no_label(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->twoStages()->labelOf(StageId::fromString('bonus'));
    }

    public function test_a_stage_cannot_be_declared_twice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->twoStages()->then(StageId::fromString('main'), 'Main game again');
    }

    public function test_a_label_cannot_be_blank_or_break_a_layout(): void
    {
        foreach (['', '   ', "Main\ngame"] as $label) {
            try {
                StageSequence::of(StageId::fromString('main'), $label);
                $this->fail("The label '{$label}' should not be accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
