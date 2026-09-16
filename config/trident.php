<?php

declare(strict_types=1);

/*
 * Single home for the business constants, limits and TTLs.
 * None of this is hardcoded inside a handler.
 *
 * The keys marked with «twin» have a counterpart in the frontend that MUST
 * match; if you change one, change the other (see api-contract.md).
 */
return [

    // Default ruleset for new games. Phase 5 changes it to "trident.v1".
    // An in-flight game is pinned to its own via games.rule_set_id, not to this.
    'default_rule_set' => env('TRIDENT_DEFAULT_RULE_SET', 'sandbox.v1'),

    // "ordered_pairs_49" | "double_six_28".
    // NOT decided: see documentation/conventions/tile-deck.md, question 8.
    'deck' => [
        'mode' => env('TRIDENT_DECK_MODE', 'ordered_pairs_49'),
    ],

    // Twin: NEXT_PUBLIC_TRIDENT_MIN_PLAYERS / _MAX_PLAYERS
    'min_players' => 3,
    'max_players' => 15,

    // Twin: NEXT_PUBLIC_TRIDENT_NICKNAME_MIN / _MAX
    // 2, not 4: "Bo" and "Al" are real names (see waived-golden-rules.md).
    'nickname_min' => 2,
    'nickname_max' => 24,

    // Sliding window: refreshed on every write, never an absolute clock.
    'idle_timeout_minutes' => (int) env('TRIDENT_IDLE_TIMEOUT_MINUTES', 180),

    // Crockford base32, without ambiguous characters: typeable with a TV remote.
    'join_code_length' => 6,
    'takeover_code_length' => 4,

    // Twin: NEXT_PUBLIC_TRIDENT_RECONCILE_POLL_MS
    // The television always reconciles; that is what saves it from a downed Reverb.
    'reconcile_poll_ms' => 30_000,
];
