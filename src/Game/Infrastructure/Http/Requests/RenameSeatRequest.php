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
            'nickname.required' => 'A name is missing.',
            'nickname.string' => 'The name must be text.',
        ];
    }

    public function toCommand(string $gameId, int $seat): RenameSeatCommand
    {
        return new RenameSeatCommand($gameId, $seat, (string) $this->validated()['nickname']);
    }
}
