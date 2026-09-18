<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Http\Requests;

use Src\Game\Application\Command\ConfigureRoom\ConfigureRoomCommand;
use Src\Shared\Infrastructure\Http\ApiFormRequest;

/**
 * It validates **only the outer shape**: that `room_config` is a flat object of
 * a bounded size. Which keys exist, what they accept and how long a text may be
 * is declared by the ruleset's `roomConfigSpec()` and checked against it in the
 * handler. A rule named here — a `challenge.face.0`, a `drawn_tiles.main` —
 * would put a ruleset's schema in the HTTP layer, which is the same failure as a
 * column with a rule's name in it.
 */
final class ConfigureRoomRequest extends ApiFormRequest
{
    /**
     * A table declares fewer than a dozen settings today. The cap is what keeps
     * a refusal that names every unknown key from naming ten thousand of them.
     */
    private const MAX_KEYS = 64;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            ...self::WRITE_RULES,
            // `present` and not `required`: an empty object is the payload that
            // puts every setting back to its default, because what is stored is
            // what was submitted and absence always takes the spec's default
            // (documentation/conventions/room-config.md). `required` counts an
            // empty array as missing and would make that submission impossible.
            'room_config' => 'present|array|max:'.self::MAX_KEYS,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_config.present' => 'The table settings are missing.',
            'room_config.array' => 'The table settings must be an object.',
            'room_config.max' => 'That is more settings than a table has.',
        ];
    }

    public function toCommand(string $gameId): ConfigureRoomCommand
    {
        /** @var array<string, mixed> $settings */
        $settings = $this->validated()['room_config'];

        return new ConfigureRoomCommand($gameId, $settings, $this->expectedVersion(), $this->requestId());
    }
}
