<?php

declare(strict_types=1);

namespace Src\Game\Domain\Repository;

use Closure;
use DateTimeImmutable;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Shared\Domain\ValueObjects\RequestId;

/**
 * The system's only repository: one aggregate, one place that reads and writes
 * it, and no loose Eloquent anywhere else.
 *
 * Seats do NOT have a repository of their own: they are always loaded and saved
 * through their game, and no route addresses them separately.
 */
interface GameRepository
{
    public function find(GameId $id): ?Game;

    /**
     * The same read, holding the game against every other writer until the unit
     * of work around it ends.
     *
     * A write protocol whose guards read outside the transaction that saves is
     * four unsynchronised statements: two taps that overlap both read the same
     * version, both pass the replay check and the version check, and the second
     * one dies on `unique(game_id, seq)` as a 500 instead of being answered as a
     * replay or as a conflict. Reading through this method inside
     * `transactional()` is what makes the whole protocol one atomic unit.
     */
    public function findForUpdate(GameId $id): ?Game;

    public function findByJoinCode(JoinCode $code): ?Game;

    /**
     * The games nobody has written to since `$cutoff` and that have not already
     * ended, oldest first.
     *
     * It answers **identities and not aggregates**, and that is the point: the
     * sweep that uses it re-reads each one under its own lock inside its own
     * unit of work. A sweep that held every idle game at once would take the
     * lock off a table that is mid-draw the moment somebody comes back to it.
     *
     * `$limit` bounds one sweep. A sweep that ran unbounded would, on the one
     * night the scheduler had been down for a week, hold a transaction open
     * across every game the product has ever served.
     *
     * @return list<GameId>
     */
    public function idleSince(DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Runs one unit of work: everything inside it commits together or not at
     * all, and a nested `save()` joins it rather than opening a second one.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function transactional(Closure $work): mixed;

    /**
     * Saves the aggregate and empties its pending move log.
     *
     * The implementation puts the row, the seats and the moves in within the same
     * transaction: a state, its roster and its move log are never readable in
     * disagreement with each other.
     *
     * `$requestId` is the write intention this save belongs to. It is stamped on
     * the entry the save appends, which is what makes the same intention sent
     * twice appendable once: `game_moves.request_id` carries a unique index, and
     * a save that appends nothing records no intention either.
     */
    public function save(Game $game, ?RequestId $requestId = null): void;

    /**
     * The game an already-recorded write intention belongs to **and what it was
     * for**, or null when the id has never been seen.
     *
     * It is the read half of the idempotency ledger: a repeated `X-Request-Id`
     * is answered with the state its first attempt produced, instead of
     * advancing the turn a second time. The kind travels with it because an id
     * identifies one intention and not one game: without it, an id already spent
     * on this game answers a different write with somebody else's result and a
     * success message.
     *
     * @return array{game: GameId, kind: string}|null
     */
    public function intentionOfRequest(RequestId $requestId): ?array;
}
