<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\GetRoomConfigSpec;

use Src\Game\Domain\Rules\RoomConfigSpec;

/**
 * The settings one game's ruleset declares, which is what the lobby form is
 * generated from.
 *
 * It is not state: it carries no version, it changes for no write, and it is the
 * same bytes for every game the same ruleset decides. That is why it does not
 * travel in `GameSnapshot` — see
 * `GameController::roomConfigSpec()` for the whole argument.
 *
 * `rule_set_id` rides along because the form and the validator have to be the
 * same declaration: the id says which one answered, so a lobby read and the
 * `room-config` write that follows it can be shown to agree.
 */
final class DeclaredRoomConfig
{
    public function __construct(
        public readonly string $ruleSetId,
        public readonly RoomConfigSpec $spec,
    ) {}

    /**
     * @return array{
     *     rule_set_id: string,
     *     fields: list<array{key: string, kind: string, label: string, default: string|bool, max_length: int|null, options: list<string>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'rule_set_id' => $this->ruleSetId,
            // In declaration order, which is the order the lobby paints them.
            'fields' => $this->spec->toArray(),
        ];
    }
}
