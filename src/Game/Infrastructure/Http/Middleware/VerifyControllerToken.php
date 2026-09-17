<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Shared\Domain\Exceptions\BusinessException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the phone that is running the game may write.
 *
 * Unlike channel authorisation — where there is nothing to deny because nobody
 * writes over the socket —, here we do deny: this is the mutation path.
 */
final class VerifyControllerToken
{
    private const HEADER = 'X-Trident-Controller-Token';

    public function __construct(private readonly GameRepository $games) {}

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->header(self::HEADER);

        if (! is_string($raw) || $raw === '') {
            throw new BusinessException(
                'controller_token_required',
                'Only the phone running the game can do that.',
                401,
            );
        }

        $game = $this->games->find($this->gameId($request)) ?? throw new GameNotFoundException;

        try {
            $token = ControllerToken::fromString($raw);
        } catch (InvalidArgumentException) {
            throw $this->invalid();
        }

        if (! $game->isControlledBy($token)) {
            throw $this->invalid();
        }

        return $next($request);
    }

    private function gameId(Request $request): GameId
    {
        try {
            return GameId::fromString((string) $request->route('gameId'));
        } catch (InvalidArgumentException) {
            throw new GameNotFoundException;
        }
    }

    private function invalid(): BusinessException
    {
        return new BusinessException(
            'controller_token_invalid',
            'This phone is no longer running the game.',
            403,
        );
    }
}
