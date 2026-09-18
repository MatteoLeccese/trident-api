<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\PlayAgain;

use InvalidArgumentException;
use Src\Game\Application\Command\CreateGame\CreatedGame;
use Src\Game\Application\Service\GameProjector;
use Src\Game\Application\Service\GameRules;
use Src\Game\Application\Service\JoinCodeMint;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\Seat;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Service\StatePublisher;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Shared\Domain\Service\Clock;

/**
 * The same table, a new game. **It is not a reset.**
 *
 * It opens a game of its own — new `GameId`, new `JoinCode`, new
 * `ControllerToken` — carrying the seats, their order and the settings the table
 * wrote, and it **closes the game it came from**.
 *
 * Closing it is not tidiness, it is the only correct ending for that game. The
 * reply carries a plaintext token and
 * documentation/conventions/credential-model.md line 26 says the BFF **rotates**
 * the `trident_controller` cookie with it: the previous game's token stops
 * writing the moment the next game exists, and the server holds it only as a
 * sha256 hash that no route reissues. A game left running after a rematch is
 * therefore a game nobody can ever write to again, stranded mid-play holding a
 * join code until it expires. `abandon()` is what the framework already calls
 * that ending, it releases the code, and it is a no-op on a game that is already
 * terminal, so a rematch of a finished table writes nothing to it.
 *
 * What it does **not** do is empty that game's entries: the append-only log is
 * what the aggregate's invariants rest on, and clearing the `request_id` index
 * would let a replayed intention from the previous game apply again — the exact
 * defect idempotency exists to prevent.
 *
 * **Roles do not carry over.** The new game has no trident until someone draws
 * the electing tile in its own first stage: a role is assigned by an effect of
 * the ruleset, no rule looks at a previous game (TR-56), and nothing survives a
 * game (TR-54, TR-55). Carrying one across would be the only place in the system
 * where the framework invented one.
 *
 * It is the **second and last** route that emits a plaintext token.
 *
 * That credential is also why the route takes no write intention: a replay could
 * not answer with the same bytes, because the token is stored only as a hash and
 * the reply without it would leave the BFF holding the wrong game's cookie. A
 * repeated tap therefore opens a second game, which the table leaves behind to
 * expire — the same outcome as tapping "new game" twice.
 */
final class PlayAgainHandler
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly StatePublisher $publisher,
        private readonly Clock $clock,
        private readonly GameProjector $projector,
        private readonly JoinCodeMint $codes,
        private readonly GameRules $rules,
    ) {}

    public function handle(PlayAgainCommand $command): CreatedGame
    {
        $id = $this->gameId($command->gameId);
        $token = ControllerToken::generate();

        /** @var array{previous: GameSnapshot, next: GameSnapshot} $snapshots */
        $snapshots = $this->games->transactional(function () use ($id, $token): array {
            $previous = $this->games->findForUpdate($id) ?? throw new GameNotFoundException;

            $next = Game::open(
                GameId::random(),
                $this->codes->mint(),
                $token,
                // The same names in the same seats. `fromNicknames` mints a fresh
                // identity per seat and carries no role, which is what makes the
                // new table the old table without its history.
                SeatRoster::fromNicknames(array_map(
                    static fn (Seat $seat): Nickname => $seat->nickname(),
                    $previous->seats()->seats(),
                )),
                $this->clock,
            );

            // Through the same lobby write any phone would use: there is one
            // resolution point and it is `start()`. A table that configured
            // nothing configures nothing here, and that write records no entry
            // and bumps no version.
            $next->configureRoom($this->settingsWrittenBy($previous), $this->clock);

            $previous->abandon($this->clock);

            $this->games->save($next);
            $this->games->save($previous);

            return [
                'previous' => $this->projector->project($previous),
                'next' => $this->projector->project($next),
            ];
        });

        // Both televisions hear about it: the one watching the table that just
        // closed, and the one already pointed at the game that replaces it.
        $this->publisher->publish($snapshots['previous']);
        $this->publisher->publish($snapshots['next']);

        return new CreatedGame($snapshots['next'], $token);
    }

    /**
     * The settings the table **wrote**, which is not the same map as the one it
     * plays with.
     *
     * `start()` resolves the stored map through the ruleset's spec and stores the
     * result, so every game that has begun holds a full map of declared keys —
     * the table's own words where it wrote some, and the ruleset's defaults
     * everywhere else. Copying that wholesale would record today's defaults as a
     * choice the next table made, and a table playing rematch after rematch would
     * keep the placeholders of TR-51 for ever, long after the real texts land.
     * So a value that equals the default it came from is not carried: absence is
     * always legal, and the default is applied once, at the next `start()`
     * (documentation/conventions/room-config.md).
     */
    private function settingsWrittenBy(Game $previous): RoomConfig
    {
        $defaults = $this->rules->of($previous)->roomConfigSpec()->defaults();
        $written = [];

        foreach ($previous->roomConfig()->toArray() as $key => $value) {
            if (array_key_exists($key, $defaults) && $defaults[$key] === $value) {
                continue;
            }

            $written[$key] = $value;
        }

        return RoomConfig::fromArray($written);
    }

    private function gameId(string $raw): GameId
    {
        try {
            return GameId::fromString($raw);
        } catch (InvalidArgumentException) {
            // A malformed id is a game that does not exist, not a 500.
            throw new GameNotFoundException;
        }
    }
}
