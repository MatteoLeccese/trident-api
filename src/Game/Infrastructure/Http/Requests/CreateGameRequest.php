<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Requests;

use Src\Game\Application\Command\CreateGame\CreateGameCommand;
use Src\Shared\Infrastructure\Http\ApiFormRequest;

final class CreateGameRequest extends ApiFormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        // Shape only. The real limits (3..15, unique names, 2..24 characters) are
        // enforced by SeatRoster, so that they hold outside HTTP too.
        return [
            'nicknames' => 'required|array',
            'nicknames.*' => 'required|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // Inherited from the old StoreGameRequest: the only user-facing copy the
        // backend ever had.
        return [
            'nicknames.required' => 'Hace falta la lista de jugadores.',
            'nicknames.array' => 'La lista de jugadores debe ser una lista.',
            'nicknames.*.required' => 'Hay un jugador sin nombre.',
            'nicknames.*.string' => 'El nombre de un jugador debe ser texto.',
        ];
    }

    public function toCommand(): CreateGameCommand
    {
        /** @var list<string> $nicknames */
        $nicknames = array_values($this->validated()['nicknames']);

        return new CreateGameCommand($nicknames);
    }
}
