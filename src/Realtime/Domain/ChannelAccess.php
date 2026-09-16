<?php

declare(strict_types=1);

namespace Src\Realtime\Domain;

/**
 * Read port for channel authorization.
 *
 * It answers a single question: **"can you watch this game?"**. Never "can you
 * write?", because nobody writes over the socket: every mutation is an HTTPS
 * POST. That is why the worst that can happen if this gets it wrong is that a
 * stranger sees a domino.
 */
interface ChannelAccess
{
    public function lookup(string $gameId): ?ChannelSubject;
}
