<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\Rules\Effect;
use Src\Game\Domain\Rules\EffectKind;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * The bytes of an effect are a contract with two clients and a jsonb column, so
 * this file pins them literally.
 */
final class EffectTest extends TestCase
{
    public function test_a_challenge_travels_with_its_key_and_never_its_text(): void
    {
        $effect = Effect::challenge(SeatNumber::fromInt(1), 'challenge.face.3');

        $this->assertSame(
            ['kind' => 'challenge', 'seat' => 1, 'config_key' => 'challenge.face.3'],
            $effect->toArray(),
        );
        $this->assertSame(
            '{"kind":"challenge","seat":1,"config_key":"challenge.face.3"}',
            json_encode($effect),
        );
    }

    public function test_the_seat_of_a_challenge_is_its_recipient(): void
    {
        // In `main` the challenge of a face of three is addressed to the trident's
        // seat and not to whoever drew the tile: that exception is the whole
        // reason this class carries a target.
        $trident = SeatNumber::fromInt(4);

        $this->assertSame(4, Effect::challenge($trident, 'challenge.face.3')->seat()?->value());
    }

    public function test_a_challenge_with_no_target_addresses_the_whole_table(): void
    {
        $effect = Effect::challenge(null, 'challenge.face.0');

        $this->assertNull($effect->seat());
        $this->assertSame(
            ['kind' => 'challenge', 'seat' => null, 'config_key' => 'challenge.face.0'],
            $effect->toArray(),
        );
    }

    public function test_a_role_is_an_opaque_string_the_framework_does_not_read(): void
    {
        $effect = Effect::assignRole(SeatNumber::fromInt(2), 'trident');

        $this->assertSame(EffectKind::ASSIGN_ROLE, $effect->kind());
        $this->assertSame('trident', $effect->role());
        $this->assertSame(
            ['kind' => 'assign_role', 'seat' => 2, 'role' => 'trident'],
            $effect->toArray(),
        );
    }

    public function test_an_announcement_indexes_copy_the_application_ships(): void
    {
        $effect = Effect::announce('stage.changed', ['stage' => 'main']);

        $this->assertSame(
            ['kind' => 'announce', 'message_key' => 'stage.changed', 'params' => ['stage' => 'main']],
            $effect->toArray(),
        );
        $this->assertSame([], Effect::announce('stage.changed')->params());
    }

    public function test_every_kind_carries_its_own_keys_and_no_others(): void
    {
        $this->assertSame(['kind', 'message_key', 'params'], array_keys(Effect::announce('a.b')->toArray()));
        $this->assertSame(['kind', 'seat', 'role'], array_keys(Effect::assignRole(SeatNumber::first(), 'trident')->toArray()));
        $this->assertSame(['kind', 'seat', 'config_key'], array_keys(Effect::challenge(null, 'a.b')->toArray()));
    }

    public function test_it_reads_back_from_the_payload_it_was_persisted_as(): void
    {
        $effects = [
            Effect::challenge(SeatNumber::fromInt(3), 'challenge.face.3'),
            Effect::challenge(null, 'challenge.face.6'),
            Effect::assignRole(SeatNumber::fromInt(2), 'trident'),
            Effect::announce('stage.changed', ['stage' => 'main']),
        ];

        foreach ($effects as $effect) {
            $stored = json_decode((string) json_encode($effect), true);

            $this->assertSame($effect->toArray(), Effect::fromArray($stored)->toArray());
        }
    }

    public function test_a_payload_that_is_not_an_effect_is_refused(): void
    {
        foreach (
            [
                [],
                ['kind' => 'drink'],
                ['kind' => 'challenge'],
                ['kind' => 'challenge', 'seat' => '1', 'config_key' => 'challenge.face.3'],
                ['kind' => 'assign_role', 'seat' => 1],
                ['kind' => 'announce', 'params' => []],
            ] as $payload
        ) {
            try {
                Effect::fromArray($payload);
                $this->fail('That payload is not an effect: '.json_encode($payload));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_challenge_addresses_the_room_configuration_key_space(): void
    {
        foreach (['', 'Challenge.face.3', 'challenge face 3', 'challenge..face'] as $key) {
            try {
                Effect::challenge(null, $key);
                $this->fail("The key '{$key}' should not be accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_role_is_a_machine_token_and_never_a_phrase(): void
    {
        foreach (['', 'The Trident', 'trident!', str_repeat('t', 33)] as $role) {
            try {
                Effect::assignRole(SeatNumber::first(), $role);
                $this->fail("The role '{$role}' should not be accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_announcement_parameter_is_a_named_scalar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Effect::announce('stage.changed', ['seats' => ['one', 'two']]);
    }
}
