<?php

declare(strict_types=1);

namespace Src\Game\Application\Service;

use Closure;
use InvalidArgumentException;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Exceptions\GameVersionConflictException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Service\StatePublisher;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Shared\Domain\Exceptions\BusinessException;
use Src\Shared\Domain\ValueObjects\RequestId;

/**
 * The write protocol every mutation of a game goes through: resolve, refuse a
 * replay, refuse a stale write, apply, persist, project, publish.
 *
 * It is not a second framework loop — it decides nothing about a game, and the
 * aggregate is still the only class that moves a cursor or deals a pool. What it
 * owns is the part every handler would otherwise re-implement, and the two
 * guards the table's network makes necessary:
 *
 *  - **The replay.** `game_moves.request_id` carries a unique index, and that
 *    index is the ledger: an `X-Request-Id` already in it is answered with the
 *    state its first attempt produced, so a double tap on bad wifi advances the
 *    turn once.
 *  - **The version.** `expected_version` is compared against the version just
 *    read, and a mismatch is refused **with the current state in `data`** so the
 *    phone heals from the refusal itself
 *    (documentation/conventions/state-versioning.md).
 *
 * The order of the two is load-bearing. A retry carries the `expected_version`
 * of the attempt that already succeeded, so checking the version first would
 * answer every successful retry with a conflict instead of with its own result.
 *
 * **The whole protocol is one unit of work.** The read is a locking read and it
 * happens inside the transaction the save commits, so two taps that overlap are
 * serialised rather than both passing every guard: the second one reads the
 * version the first one wrote, and is answered as a replay or as a version
 * conflict instead of dying on `unique(game_id, seq)` as a 500. Nothing is
 * published until that transaction has committed.
 *
 * **A write intention names one write, not one game.** The ledger is read back
 * with the kind of entry it produced, and a replay is answered only when the
 * game and the kind both match: an id spent on a start and then sent with a draw
 * is a reused id, not a repeat, and answering it with the start's projection and
 * a success message would be a tile that never turned over.
 *
 * The version compared is the one the write was **built on**: at that point no
 * write method has run, so the aggregate's current version is still the version
 * it was read at. The two diverge only afterwards, which is why the guard sits
 * before `$write` and not after it.
 *
 * A write that changed nothing is not a write: no version, no entry in the log,
 * no broadcast and no ledger entry, so repeating it is harmless whether or not
 * it carried an id.
 */
final class GameWriter
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly StatePublisher $publisher,
        private readonly GameProjector $projector,
    ) {}

    /**
     * @param  string  $intent  the `MoveKind` this mutation appends, which is what
     *                          the write intention is bound to in the ledger
     * @param  Closure(Game): void  $write  the one aggregate method this mutation calls
     */
    public function write(
        string $gameId,
        ?int $expectedVersion,
        ?string $requestId,
        string $intent,
        Closure $write,
    ): GameSnapshot {
        $id = $this->gameId($gameId);
        $request = $this->requestId($requestId);

        /** @var array{snapshot: GameSnapshot, publish: bool} $result */
        $result = $this->games->transactional(function () use ($id, $request, $expectedVersion, $intent, $write): array {
            // The locking read: from here to the commit this game has one writer.
            $game = $this->games->findForUpdate($id) ?? throw new GameNotFoundException;

            if ($request !== null) {
                $replay = $this->replayOf($game, $request, $intent);

                if ($replay !== null) {
                    return ['snapshot' => $replay, 'publish' => false];
                }
            }

            $this->assertWritingOn($game, $expectedVersion);

            $before = $game->version();

            // If the aggregate refuses, nothing has been saved or emitted.
            $write($game);

            if ($game->version()->equals($before)) {
                return ['snapshot' => $this->projector->project($game), 'publish' => false];
            }

            $this->games->save($game, $request);

            // One projection, built once: the television and the phone read the
            // same object, not two assemblies of it.
            return ['snapshot' => $this->projector->project($game), 'publish' => true];
        });

        // After the commit and never inside it: a television must not be told
        // about a version a rollback is about to take away.
        if ($result['publish']) {
            $this->publisher->publish($result['snapshot']);
        }

        return $result['snapshot'];
    }

    /**
     * The state a spent write intention already produced, or null when this id
     * has never been seen.
     */
    private function replayOf(Game $game, RequestId $request, string $intent): ?GameSnapshot
    {
        $spent = $this->games->intentionOfRequest($request);

        if ($spent === null) {
            return null;
        }

        if ($spent['game']->equals($game->id()) && $spent['kind'] === $intent) {
            return $this->projector->project($game);
        }

        // The ledger is global, and the same id can only ever mean one
        // intention: another game's, or another gesture's on this one. Applying
        // it here would be the defect idempotency exists to prevent, so it is
        // refused rather than replayed against a write it never addressed.
        throw new BusinessException(
            'request_id_reused',
            'That request has already been used for another write.',
            422,
        );
    }

    private function assertWritingOn(Game $game, ?int $expectedVersion): void
    {
        if ($expectedVersion === null || $game->version()->value() === $expectedVersion) {
            return;
        }

        throw new GameVersionConflictException(
            $this->projector->project($game)->toArray(),
            $expectedVersion,
            $game->version()->value(),
        );
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

    private function requestId(?string $raw): ?RequestId
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return RequestId::fromString($raw);
        } catch (InvalidArgumentException) {
            // The column is `uuid`: a malformed id has to be refused here, or the
            // driver refuses it as a 500 at the end of a write that has already
            // been applied in memory.
            throw new BusinessException(
                'request_id_invalid',
                'That request identifier is not a UUID.',
                422,
            );
        }
    }
}
