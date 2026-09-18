<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\ConfigureRoom;

use Src\Game\Application\Service\GameRules;
use Src\Game\Application\Service\GameWriter;
use Src\Game\Domain\Exceptions\InvalidRoomConfigValueException;
use Src\Game\Domain\Exceptions\UnknownRoomConfigKeyException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Shared\Domain\Service\Clock;

/**
 * The table's settings, which only the lobby accepts.
 *
 * The submitted map is checked against the spec the ruleset declares, and the
 * framework does that **without understanding it**: an undeclared key and a
 * value a field does not accept are each a refusal naming the key, and no class
 * here knows what any of those keys mean
 * (documentation/conventions/room-config.md).
 *
 * What is stored is what was submitted, not what it resolves to. Absence is
 * always legal and there is exactly one resolution point — `start()` — so the
 * lobby keeps holding the table's own words until play begins.
 */
final class ConfigureRoomHandler
{
    public function __construct(
        private readonly GameWriter $writer,
        private readonly GameRules $rules,
        private readonly Clock $clock,
    ) {}

    public function handle(ConfigureRoomCommand $command): GameSnapshot
    {
        return $this->writer->write(
            $command->gameId,
            $command->expectedVersion,
            $command->requestId,
            MoveKind::ROOM_CONFIGURED,
            function (Game $game) use ($command): void {
                $spec = $this->rules->of($game)->roomConfigSpec();

                $unknown = $spec->unknownKeys($command->settings);

                if ($unknown !== []) {
                    throw new UnknownRoomConfigKeyException($unknown);
                }

                $invalid = $spec->invalidKeys($command->settings);

                if ($invalid !== []) {
                    throw new InvalidRoomConfigValueException($invalid);
                }

                $game->configureRoom(RoomConfig::fromArray($command->settings), $this->clock);
            },
        );
    }
}
