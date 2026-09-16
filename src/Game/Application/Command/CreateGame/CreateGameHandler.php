<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\CreateGame;

use RuntimeException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Service\StatePublisher;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Shared\Domain\Service\Clock;

final class CreateGameHandler
{
    private const JOIN_CODE_ATTEMPTS = 8;

    public function __construct(
        private readonly GameRepository $games,
        private readonly StatePublisher $publisher,
        private readonly Clock $clock,
    ) {}

    public function handle(CreateGameCommand $command): CreatedGame
    {
        // The roster validates before anything is generated: an invalid table must
        // leave behind neither a created token nor a consumed code.
        $roster = SeatRoster::fromNicknames(
            array_map(Nickname::fromString(...), $command->nicknames),
        );

        $token = ControllerToken::generate();

        $game = Game::open(
            GameId::random(),
            $this->unusedJoinCode(),
            $token,
            $roster,
            $this->clock,
        );

        $this->games->save($game);
        $this->publisher->publish($game->snapshot());

        return new CreatedGame($game->snapshot(), $token);
    }

    private function unusedJoinCode(): JoinCode
    {
        for ($attempt = 0; $attempt < self::JOIN_CODE_ATTEMPTS; $attempt++) {
            $code = JoinCode::generate();

            if ($this->games->findByJoinCode($code) === null) {
                return $code;
            }
        }

        // 32^6 combinations and codes are released when a game ends: reaching here
        // means something is very wrong, not that we were unlucky.
        throw new RuntimeException('No se pudo generar un código de partida libre.');
    }
}
