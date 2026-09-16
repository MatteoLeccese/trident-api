<?php

declare(strict_types=1);

namespace Src\Realtime\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Realtime\Domain\ChannelAccess;
use Src\Realtime\Infrastructure\Broadcasting\GameParticipant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a request to `/broadcasting/auth` into a participant.
 *
 * Three rules, all verified against the Laravel source:
 *
 * 1. **It always resolves a non-null participant**, even in order to deny.
 *    `PusherBroadcaster::auth()` throws `AccessDeniedHttpException` BEFORE
 *    running any callback if `retrieveUser()` is falsy, and that 403 is mute.
 *    The one that denies is the channel callback, which can explain why.
 * 2. **It memoizes.** `retrieveUser()` is called THREE times per presence
 *    request. Without memoizing, that is three game lookups per subscription.
 * 3. **A missing token is not an error**: it is the television's normal path.
 *    Nor is a wrong one: nothing is written over the socket, so there is nothing
 *    to deny. Both are spectators.
 */
final class ResolveGameParticipant
{
    private const TOKEN_HEADER = 'X-Trident-Controller-Token';

    public function __construct(private readonly ChannelAccess $channels) {}

    public function handle(Request $request, Closure $next): Response
    {
        $participant = null;

        $request->setUserResolver(function () use ($request, &$participant): GameParticipant {
            return $participant ??= $this->resolve($request);
        });

        return $next($request);
    }

    private function resolve(Request $request): GameParticipant
    {
        $socketId = (string) $request->input('socket_id', '');
        $gameId = $this->gameIdFrom((string) $request->input('channel_name', ''));

        $subject = $gameId === null ? null : $this->channels->lookup($gameId);

        if ($subject === null) {
            // Nonexistent game: a participant that cannot watch anything. The
            // channel callback will reject it, and with an explainable 403.
            return new GameParticipant($gameId ?? '', GameParticipant::SPECTATOR, $socketId, false);
        }

        return new GameParticipant(
            $subject->gameId,
            $this->isController($request, $subject->controllerTokenHash)
                ? GameParticipant::CONTROLLER
                : GameParticipant::SPECTATOR,
            $socketId,
            $subject->isWatchable,
        );
    }

    private function isController(Request $request, string $storedHash): bool
    {
        $raw = $request->header(self::TOKEN_HEADER);

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            return ControllerToken::fromString($raw)->matchesHash($storedHash);
        } catch (InvalidArgumentException) {
            // A token with an invalid shape is simply a spectator.
            return false;
        }
    }

    /** `presence-game.{uuid}` → `{uuid}`. */
    private function gameIdFrom(string $channelName): ?string
    {
        if (preg_match('/\Apresence-game\.([0-9a-fA-F-]{36})\z/', $channelName, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}
