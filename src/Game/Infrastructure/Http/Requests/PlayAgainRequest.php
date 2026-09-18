<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Requests;

use Src\Game\Application\Command\PlayAgain\PlayAgainCommand;
use Src\Shared\Infrastructure\Http\ApiFormRequest;

/**
 * A rematch carries nothing, not even the two write guards: it writes nothing to
 * the game it is asked from, so there is no version to guard and no state a
 * repeat could be answered with. The command's docblock carries the argument.
 *
 * The seats, their order and the table's settings come from the previous game
 * and never from the request: a client that could send them here could rename
 * the table without a rename.
 */
final class PlayAgainRequest extends ApiFormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(string $gameId): PlayAgainCommand
    {
        return new PlayAgainCommand($gameId);
    }
}
