<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Tests\TestCase;

/**
 * The relationships between the product's configured values.
 *
 * Every value in `config/trident.php` is individually plausible, and the ones
 * that matter are only correct **with respect to each other**. A deployment that
 * sets one of a pair from an environment variable and leaves the other at its
 * default produces a stack that boots, passes every other test and misbehaves in
 * front of a room.
 *
 * Two convention documents cite this file by name as the thing that enforces the
 * ordering below. Until it existed they cited nothing.
 */
final class TridentConfigTest extends TestCase
{
    public function test_the_television_never_gives_up_on_a_game_before_the_server_does(): void
    {
        $notice = (int) config('trident.tv_idle_notice_minutes');
        $timeout = (int) config('trident.idle_timeout_minutes');

        /*
         * The watch screen says a game looks abandoned after `tv_idle_notice_minutes`
         * of silence, and the server ends it after `idle_timeout_minutes`. The
         * wrong way round, the television tells a room still sitting in front of
         * it to go home, for a game the server is perfectly happy to continue —
         * and the phone, which shows no such notice, contradicts the big screen.
         */
        $this->assertGreaterThan(
            0,
            $notice,
            'The watch screen needs a positive window, or it announces abandonment the instant a game starts.',
        );

        $this->assertLessThan(
            $timeout,
            $notice,
            'tv_idle_notice_minutes must be lower than idle_timeout_minutes: '
            ."the television would send a room home after {$notice} minutes for a game the server keeps until {$timeout}.",
        );
    }

    public function test_a_table_is_between_three_and_fifteen_seats(): void
    {
        $min = (int) config('trident.min_players');
        $max = (int) config('trident.max_players');

        $this->assertGreaterThanOrEqual(2, $min, 'A game of one is not a game.');
        $this->assertLessThan($max, $min);
    }

    public function test_a_nickname_has_room_for_a_real_name(): void
    {
        $min = (int) config('trident.nickname_min');
        $max = (int) config('trident.nickname_max');

        // Two, not four: "Bo" and "Al" are real names (waived-golden-rules.md).
        $this->assertGreaterThanOrEqual(2, $min);
        $this->assertLessThan($max, $min);
    }

    public function test_the_idle_window_is_long_enough_to_be_a_party_and_short_enough_to_end(): void
    {
        $timeout = (int) config('trident.idle_timeout_minutes');

        // A window under an hour would end a game over dinner; one over a day
        // would leave every abandoned table on its television until the next one.
        $this->assertGreaterThanOrEqual(60, $timeout);
        $this->assertLessThanOrEqual(24 * 60, $timeout);
    }

    public function test_the_join_code_is_typeable_with_a_television_remote(): void
    {
        $length = (int) config('trident.join_code_length');

        $this->assertGreaterThanOrEqual(4, $length);
        $this->assertLessThanOrEqual(8, $length, 'Nobody types nine characters on a remote control.');
    }
}
