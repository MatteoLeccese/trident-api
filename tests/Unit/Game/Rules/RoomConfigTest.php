<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RoomConfigField;
use Src\Game\Domain\Rules\RoomConfigSpec;

/**
 * What the table chose, resolved. Strict form, tolerant reader.
 */
final class RoomConfigTest extends TestCase
{
    private function spec(): RoomConfigSpec
    {
        return RoomConfigSpec::of(
            RoomConfigField::text('challenge.face.0', 'Face 0', 'Sip', 80),
            RoomConfigField::text('challenge.face.1', 'Face 1', 'Pick someone', 80),
            RoomConfigField::choice('drawn_tiles.main', 'Drawn tiles', ['keep', 'remove'], 'remove'),
        );
    }

    public function test_the_key_space_is_flat_and_dotted(): void
    {
        $this->assertTrue(RoomConfig::isValidKey('challenge.face.3'));
        $this->assertTrue(RoomConfig::isValidKey('drawn_tiles.election'));
        $this->assertFalse(RoomConfig::isValidKey('challenge.face.3 '));
        $this->assertFalse(RoomConfig::isValidKey('Challenge.face.3'));
        $this->assertFalse(RoomConfig::isValidKey(''));
    }

    public function test_it_never_holds_a_nested_object(): void
    {
        // Flatness is what lets a generic validator work without understanding a
        // hierarchy a ruleset invented.
        $this->expectException(InvalidArgumentException::class);

        RoomConfig::fromArray(['challenge' => ['face' => ['3' => 'Drink']]]);
    }

    public function test_a_key_outside_the_space_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoomConfig::fromArray(['Challenge Face 3' => 'Drink']);
    }

    public function test_it_reads_back_what_the_table_wrote(): void
    {
        $config = RoomConfig::fromArray(['challenge.face.3' => 'Drink', 'drawn_tiles.main' => 'remove']);

        $this->assertTrue($config->has('challenge.face.3'));
        $this->assertSame('Drink', $config->get('challenge.face.3'));
        $this->assertSame(['challenge.face.3' => 'Drink', 'drawn_tiles.main' => 'remove'], $config->toArray());
    }

    public function test_an_empty_configuration_is_legal(): void
    {
        $this->assertSame([], RoomConfig::empty()->toArray());
        $this->assertFalse(RoomConfig::empty()->has('challenge.face.0'));
        $this->assertNull(RoomConfig::empty()->get('challenge.face.0'));
    }

    public function test_the_reader_fills_every_declared_key_with_its_default(): void
    {
        // A game created before a key existed has it absent, which is exactly this
        // case: the client may assume every declared key is present.
        $config = RoomConfig::resolve(['challenge.face.0' => 'Two fingers'], $this->spec());

        $this->assertSame(
            [
                'challenge.face.0' => 'Two fingers',
                'challenge.face.1' => 'Pick someone',
                'drawn_tiles.main' => 'remove',
            ],
            $config->toArray(),
        );
    }

    public function test_the_reader_ignores_a_key_the_spec_no_longer_declares(): void
    {
        // Ignored on reading and kept on writing: a live person can be corrected,
        // a saved row is history.
        $config = RoomConfig::resolve(['packs' => 'rude', 'challenge.face.0' => 'Two fingers'], $this->spec());

        $this->assertFalse($config->has('packs'));
        $this->assertSame('Two fingers', $config->get('challenge.face.0'));
    }

    public function test_the_reader_falls_back_rather_than_handing_a_rule_something_unusable(): void
    {
        $config = RoomConfig::resolve(
            ['challenge.face.0' => 17, 'drawn_tiles.main' => 'burn'],
            $this->spec(),
        );

        $this->assertSame('Sip', $config->get('challenge.face.0'));
        $this->assertSame('remove', $config->get('drawn_tiles.main'));
    }

    public function test_the_resolved_configuration_follows_the_declared_order(): void
    {
        $config = RoomConfig::resolve(['drawn_tiles.main' => 'keep'], $this->spec());

        $this->assertSame($this->spec()->keys(), array_keys($config->toArray()));
    }

    public function test_it_travels_as_a_flat_map(): void
    {
        $config = RoomConfig::fromArray(['challenge.face.3' => 'Drink']);

        $this->assertSame('{"challenge.face.3":"Drink"}', json_encode($config));
    }
}
