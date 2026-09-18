<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\RoomConfigField;

/**
 * One declared setting. The framework validates against it without understanding
 * it: no ruleset writes a validator, and no class here knows what a challenge is.
 */
final class RoomConfigFieldTest extends TestCase
{
    private function text(): RoomConfigField
    {
        return RoomConfigField::text('challenge.face.3', 'Face 3', 'Everyone drinks', 80);
    }

    public function test_a_text_field_publishes_everything_the_lobby_needs(): void
    {
        $this->assertSame(
            [
                'key' => 'challenge.face.3',
                'kind' => 'text',
                'label' => 'Face 3',
                'default' => 'Everyone drinks',
                'max_length' => 80,
                'options' => [],
            ],
            $this->text()->toArray(),
        );
    }

    public function test_a_toggle_and_a_choice_publish_the_same_keys(): void
    {
        $toggle = RoomConfigField::toggle('board.compact', 'Compact board', false);
        $choice = RoomConfigField::choice('drawn_tiles.main', 'Drawn tiles', ['keep', 'remove'], 'remove');

        $this->assertSame(
            ['key' => 'board.compact', 'kind' => 'toggle', 'label' => 'Compact board', 'default' => false, 'max_length' => null, 'options' => []],
            $toggle->toArray(),
        );
        $this->assertSame(
            ['key' => 'drawn_tiles.main', 'kind' => 'choice', 'label' => 'Drawn tiles', 'default' => 'remove', 'max_length' => null, 'options' => ['keep', 'remove']],
            $choice->toArray(),
        );
    }

    public function test_a_text_field_counts_characters_and_not_bytes(): void
    {
        $field = RoomConfigField::text('challenge.face.0', 'Face 0', 'Sip', 4);

        $this->assertTrue($field->accepts('áéíó'));
        $this->assertFalse($field->accepts('áéíóú'));
    }

    public function test_an_empty_text_is_a_decision_the_table_may_make(): void
    {
        // An empty challenge means "this face does nothing" and paints no card.
        $this->assertTrue($this->text()->accepts(''));
    }

    public function test_a_declared_default_is_never_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoomConfigField::text('challenge.face.3', 'Face 3', '', 80);
    }

    public function test_a_text_field_refuses_control_characters(): void
    {
        // The text is painted at 96px on a television: a line break inside it
        // breaks the layout.
        $this->assertFalse($this->text()->accepts("two\nlines"));
    }

    public function test_nothing_is_ever_coerced(): void
    {
        $toggle = RoomConfigField::toggle('board.compact', 'Compact board', false);

        $this->assertTrue($toggle->accepts(true));
        $this->assertFalse($toggle->accepts('1'));
        $this->assertFalse($toggle->accepts(1));
        $this->assertFalse($toggle->accepts('true'));
        $this->assertFalse($this->text()->accepts(7));
        $this->assertFalse($this->text()->accepts(null));
    }

    public function test_a_choice_accepts_only_what_it_declared(): void
    {
        $field = RoomConfigField::choice('drawn_tiles.main', 'Drawn tiles', ['keep', 'remove'], 'remove');

        $this->assertTrue($field->accepts('keep'));
        $this->assertFalse($field->accepts('Keep'));
        $this->assertFalse($field->accepts(''));
    }

    public function test_a_field_cannot_declare_a_default_it_would_refuse(): void
    {
        foreach (
            [
                fn (): RoomConfigField => RoomConfigField::text('challenge.face.3', 'Face 3', 'far too long', 4),
                fn (): RoomConfigField => RoomConfigField::choice('drawn_tiles.main', 'Drawn tiles', ['keep'], 'remove'),
            ] as $declaration
        ) {
            try {
                $declaration();
                $this->fail('A field should not declare a default it refuses.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_key_space_is_flat_and_dotted(): void
    {
        foreach (['', 'Challenge.face.3', 'challenge face 3', 'challenge..face', '.challenge', 'challenge.'] as $key) {
            try {
                RoomConfigField::text($key, 'Label', 'Default', 80);
                $this->fail("The key '{$key}' should not be accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_field_needs_a_label_a_lobby_can_paint(): void
    {
        foreach (['', '  ', "Face\n3"] as $label) {
            try {
                RoomConfigField::text('challenge.face.3', $label, 'Default', 80);
                $this->fail('A blank or broken label should not be accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_choice_needs_distinct_options(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoomConfigField::choice('drawn_tiles.main', 'Drawn tiles', ['keep', 'keep'], 'keep');
    }
}
