<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Requests;

use Src\Game\Application\Command\RenameSeat\RenameSeatCommand;
use Src\Shared\Infrastructure\Http\ApiFormRequest;

final class RenameSeatRequest extends ApiFormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'nickname' => 'required|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nickname.required' => 'Hace falta un nombre.',
            'nickname.string' => 'El nombre debe ser texto.',
        ];
    }

    public function toCommand(string $gameId, int $seat): RenameSeatCommand
    {
        return new RenameSeatCommand($gameId, $seat, (string) $this->validated()['nickname']);
    }
}
