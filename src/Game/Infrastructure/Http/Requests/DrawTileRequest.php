<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Requests;

use Src\Game\Application\Command\DrawTile\DrawTileCommand;
use Src\Shared\Infrastructure\Http\ApiFormRequest;

/**
 * A draw carries no seat and no face: the position is in the path, the seat is
 * the one the aggregate already holds (TR-11), and the client cannot name a face
 * it has not seen.
 */
final class DrawTileRequest extends ApiFormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return self::WRITE_RULES;
    }

    public function toCommand(string $gameId, string $position): DrawTileCommand
    {
        return new DrawTileCommand($gameId, $position, $this->expectedVersion(), $this->requestId());
    }
}
