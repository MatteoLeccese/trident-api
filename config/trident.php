<?php

declare(strict_types=1);

/*
 * Single home for the business constants, limits and TTLs.
 * None of this is hardcoded inside a handler.
 *
 * The keys marked with «twin» have a counterpart in the frontend that MUST
 * match; if you change one, change the other. This comment is where that
 * contract lives: there is no separate contract document.
 */
return [

    // The only production ruleset. An in-flight game is pinned to its own via
    // games.rule_set_id, not to this key, so changing it never changes the
    // meaning of a game that is already on a table.
    'default_rule_set' => env('TRIDENT_DEFAULT_RULE_SET', 'trident.v1'),

    // Twin: NEXT_PUBLIC_TRIDENT_MIN_PLAYERS / _MAX_PLAYERS
    'min_players' => 3,
    'max_players' => 15,

    // Twin: NEXT_PUBLIC_TRIDENT_NICKNAME_MIN / _MAX
    // 2, not 4: "Bo" and "Al" are real names (see waived-golden-rules.md).
    'nickname_min' => 2,
    'nickname_max' => 24,

    // Sliding window: refreshed on every write, never an absolute clock.
    'idle_timeout_minutes' => (int) env('TRIDENT_IDLE_TIMEOUT_MINUTES', 180),

    // Minutes of silence after which the watch screen says the game looks
    // abandoned. Always lower than idle_timeout_minutes, or the television
    // sends people home for a game the server has not expired.
    // Twin: the tv_idle_notice_minutes field of GameSnapshot, read by the watch
    // screen in trident-web. The client keeps no copy of its own value.
    // The ordering against idle_timeout_minutes is enforced by
    // tests/Unit/Shared/TridentConfigTest.php, not left to whoever edits this.
    'tv_idle_notice_minutes' => (int) env('TRIDENT_TV_IDLE_NOTICE_MINUTES', 30),

    // Crockford base32, without ambiguous characters: typeable with a TV remote.
    'join_code_length' => 6,

    // Twin: NEXT_PUBLIC_TRIDENT_RECONCILE_POLL_MS
    // The television always reconciles; that is what saves it from a downed Reverb.
    'reconcile_poll_ms' => 30_000,
];
