<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\CreateGame;

use Src\Game\Application\Service\GameProjector;
use Src\Game\Application\Service\JoinCodeMint;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Service\StatePublisher;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Shared\Domain\Service\Clock;

final class CreateGameHandler
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly StatePublisher $publisher,
        private readonly Clock $clock,
        private readonly GameProjector $projector,
        private readonly JoinCodeMint $codes,
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
            $this->codes->mint(),
            $token,
            $roster,
            $this->clock,
        );

        $this->games->save($game);

        // One projection, built once: the television and the phone read the same
        // object, not two assemblies of it.
        $snapshot = $this->projector->project($game);

        $this->publisher->publish($snapshot);

        return new CreatedGame($snapshot, $token);
    }
}
