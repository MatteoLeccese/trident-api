<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\ResolveJoinCode;

use InvalidArgumentException;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\JoinCode;

/**
 * From the code someone types on the television to the game.
 */
final class ResolveJoinCodeHandler
{
    public function __construct(private readonly GameRepository $games) {}

    public function handle(ResolveJoinCodeQuery $query): GameSnapshot
    {
        try {
            $code = JoinCode::fromString($query->code);
        } catch (InvalidArgumentException) {
            // A mistyped code is "does not exist", not a validation error: the
            // difference makes no difference to the viewer.
            throw new GameNotFoundException;
        }

        $game = $this->games->findByJoinCode($code) ?? throw new GameNotFoundException;

        return $game->snapshot();
    }
}
