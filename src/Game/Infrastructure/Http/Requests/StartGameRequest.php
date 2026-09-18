<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Requests;

use Src\Game\Application\Command\StartGame\StartGameCommand;
use Src\Shared\Infrastructure\Http\ApiFormRequest;

/**
 * Starting a game has no payload of its own: what stage it opens in, what pool
 * it deals and who draws first are decisions of the ruleset and of the
 * aggregate, and a client that could name any of them would be naming a rule.
 */
final class StartGameRequest extends ApiFormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return self::WRITE_RULES;
    }

    public function toCommand(string $gameId): StartGameCommand
    {
        return new StartGameCommand($gameId, $this->expectedVersion(), $this->requestId());
    }
}
