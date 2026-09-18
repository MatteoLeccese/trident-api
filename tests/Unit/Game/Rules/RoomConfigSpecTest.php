<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\RoomConfigField;
use Src\Game\Domain\Rules\RoomConfigSpec;

/**
 * The whole surface a room setting plugs into: a new setting is one more entry
 * here, never a column, an endpoint or a branch in the client.
 */
final class RoomConfigSpecTest extends TestCase
{
    private function spec(): RoomConfigSpec
    {
        return RoomConfigSpec::of(
            RoomConfigField::text('challenge.face.0', 'Face 0', 'Sip', 80),
            RoomConfigField::text('challenge.face.1', 'Face 1', 'Pick someone', 80),
            RoomConfigField::choice('drawn_tiles.main', 'Drawn tiles', ['keep', 'remove'], 'remove'),
        );
    }

    public function test_it_keeps_the_order_the_ruleset_declared(): void
    {
        $this->assertSame(
            ['challenge.face.0', 'challenge.face.1', 'drawn_tiles.main'],
            $this->spec()->keys(),
        );
    }

    public function test_the_lobby_form_is_generated_from_it(): void
    {
        $form = $this->spec()->toArray();

        $this->assertCount(3, $form);
        $this->assertSame('challenge.face.0', $form[0]['key']);
        $this->assertSame('choice', $form[2]['kind']);
        $this->assertSame(['keep', 'remove'], $form[2]['options']);
    }

    public function test_it_publishes_a_default_for_every_declared_key(): void
    {
        $this->assertSame(
            [
                'challenge.face.0' => 'Sip',
                'challenge.face.1' => 'Pick someone',
                'drawn_tiles.main' => 'remove',
            ],
            $this->spec()->defaults(),
        );
    }

    public function test_it_hands_back_the_field_behind_a_key(): void
    {
        $this->assertTrue($this->spec()->has('drawn_tiles.main'));
        $this->assertFalse($this->spec()->has('drawn_tiles.election'));
        $this->assertSame(80, $this->spec()->field('challenge.face.0')->maxLength());
    }

    public function test_a_key_it_does_not_declare_has_no_field(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->spec()->field('drawn_tiles.election');
    }

    public function test_a_submitted_key_it_does_not_declare_is_named_back(): void
    {
        // Never discarded in silence: somebody wrote something and pressed save.
        $this->assertSame(
            ['challenge.face.9', 'packs'],
            $this->spec()->unknownKeys([
                'challenge.face.0' => 'Sip',
                'packs' => 'rude',
                'challenge.face.9' => 'nope',
            ]),
        );
    }

    public function test_a_malformed_value_is_named_back_and_never_coerced(): void
    {
        $this->assertSame(
            ['challenge.face.1', 'drawn_tiles.main'],
            $this->spec()->invalidKeys([
                'challenge.face.0' => 'Sip',
                'challenge.face.1' => str_repeat('x', 81),
                'drawn_tiles.main' => 'burn',
            ]),
        );
    }

    public function test_absence_is_always_legal(): void
    {
        $this->assertSame([], $this->spec()->unknownKeys([]));
        $this->assertSame([], $this->spec()->invalidKeys([]));
    }

    public function test_a_key_cannot_be_declared_twice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoomConfigSpec::of(
            RoomConfigField::text('challenge.face.0', 'Face 0', 'Sip', 80),
            RoomConfigField::text('challenge.face.0', 'Face 0 again', 'Sip', 80),
        );
    }

    public function test_a_ruleset_may_declare_no_settings_at_all(): void
    {
        $this->assertSame([], RoomConfigSpec::of()->fields());
        $this->assertSame([], RoomConfigSpec::of()->defaults());
    }
}
