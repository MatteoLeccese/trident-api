<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\GetRoomConfigSpec;

use InvalidArgumentException;
use Src\Game\Application\Service\GameRules;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\GameId;

/**
 * The declaration the lobby form is generated from.
 *
 * It resolves the ruleset through `GameRules` and not through `GameProjector`,
 * and the difference is the whole point: `GameRules` answers with the ruleset a
 * write on this game is decided by — the one it was pinned to at `start()`, or
 * the deployment's default while it is pinned to none — which is exactly the
 * declaration `ConfigureRoomHandler` validates a submission against. A form
 * generated from any other one could paint a box the validator refuses.
 */
final class GetRoomConfigSpecHandler
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly GameRules $rules,
    ) {}

    public function handle(GetRoomConfigSpecQuery $query): DeclaredRoomConfig
    {
        try {
            $id = GameId::fromString($query->gameId);
        } catch (InvalidArgumentException) {
            // A malformed id is a game that does not exist, not a 500.
            throw new GameNotFoundException;
        }

        $game = $this->games->find($id) ?? throw new GameNotFoundException;

        $rules = $this->rules->of($game);

        return new DeclaredRoomConfig($rules->id(), $rules->roomConfigSpec());
    }
}
