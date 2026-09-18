<?php

declare(strict_types=1);

namespace Src\Game\Application\Command\ExpireIdleGames;

use DateInterval;
use InvalidArgumentException;
use Src\Game\Application\Service\GameProjector;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Service\StatePublisher;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Shared\Domain\Service\Clock;

/**
 * Ends the games the table walked away from, and tells the room.
 *
 * **Every expiry publishes.** A television holds a socket open all evening and
 * reads nothing but what arrives on it: a game that died on the server without a
 * broadcast leaves the big screen showing somebody's turn for ever, which is the
 * exact failure the watch screen exists to prevent. This is the only write path
 * outside `GameWriter` and `PlayAgainHandler` that publishes, and it publishes
 * for the same reason they do.
 *
 * **One game per unit of work, never one transaction for the sweep.** Each game
 * is re-read under its own lock, ended and committed on its own, so a sweep that
 * walks a backlog of two hundred games never holds a lock on the one table that
 * has just come back to life.
 *
 * **The status is re-checked after the lock.** A phone that drew a tile between
 * the query and the lock has made the game not idle any more, and the query's
 * answer is by then out of date; the aggregate refuses the second write and the
 * version says so, which is what the version comparison below reads.
 */
final class ExpireIdleGamesHandler
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly StatePublisher $publisher,
        private readonly GameProjector $projector,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<GameSnapshot> the games this sweep ended, in the order it ended them
     */
    public function handle(ExpireIdleGamesCommand $command): array
    {
        if ($command->idleMinutes < 1 || $command->limit < 1) {
            throw new InvalidArgumentException('A sweep takes a positive window and a positive limit.');
        }

        $cutoff = $this->clock->now()->sub(new DateInterval("PT{$command->idleMinutes}M"));
        $expired = [];

        foreach ($this->games->idleSince($cutoff, $command->limit) as $id) {
            $snapshot = $this->end($id);

            if ($snapshot !== null) {
                // After the commit and never inside it, exactly as a write does:
                // a television must not be told about a version a rollback is
                // about to take away.
                $this->publisher->publish($snapshot);

                $expired[] = $snapshot;
            }
        }

        return $expired;
    }

    /**
     * Ends one game, or answers null when there was nothing left to end.
     *
     * Null covers a game that vanished between the query and the lock and one
     * that somebody finished in the same window. Both are ordinary races and
     * neither is an error: the sweep skips them and the next one will not see
     * them at all.
     */
    private function end(GameId $id): ?GameSnapshot
    {
        return $this->games->transactional(function () use ($id): ?GameSnapshot {
            $game = $this->games->findForUpdate($id);

            if ($game === null) {
                return null;
            }

            $before = $game->version();

            $game->abandon($this->clock, FinishReason::IDLE_TIMEOUT);

            // The aggregate is the authority on whether anything happened: it
            // refuses to end a game that has already ended, and says so by
            // leaving the version where it was.
            if ($game->version()->equals($before)) {
                return null;
            }

            $this->games->save($game);

            return $this->projector->project($game);
        });
    }
}
