<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Src\Realtime\Infrastructure\Broadcasting\GameParticipant;

/*
 * Presence channel of a game.
 *
 * The channel name is `presence-game.{gameId}`; the registered pattern is
 * `game.{gameId}` because Laravel strips the prefix before matching.
 *
 * Three details that are not style but correctness, and that were verified by
 * reading `Broadcaster::verifyUserCanAccessChannel`:
 *
 *  - The participant parameter goes WITHOUT a typehint. With a typehint, a
 *    wiring failure turns into a TypeError 500 instead of a readable 403.
 *  - Returning `[]` does NOT authorize: the check is `elseif ($result)` and an
 *    empty array is falsy, so it would fall through to the final 403. Either an
 *    array with content is returned, or `false`.
 *  - The identifier goes INSIDE the array: Echo discards the `user_id` key and
 *    only delivers `user_info`, so that is the only thing the UI ever sees.
 */
Broadcast::channel('game.{gameId}', function ($participant, string $gameId): array|false {
    if (! $participant instanceof GameParticipant) {
        return false;
    }

    if (! $participant->canWatch || $participant->gameId !== strtolower($gameId)) {
        return false;
    }

    return [
        'id' => $participant->getAuthIdentifier(),
        'role' => $participant->role,
    ];
});
