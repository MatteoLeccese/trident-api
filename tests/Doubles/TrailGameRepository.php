<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Closure;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Shared\Domain\ValueObjects\RequestId;

/**
 * An in-memory repository that writes down the order it was called in.
 *
 * It exists for the one claim about the write protocol that cannot be made by
 * looking at a result: that the read, the ledger, the version guard and the save
 * all happen **inside one unit of work**, and that nothing is published until it
 * has closed. A single-process test cannot interleave two writers, so what is
 * asserted is the shape of the protocol rather than the outcome of a race.
 */
final class TrailGameRepository implements GameRepository
{
    /** @var list<string> every call, in order, with the transaction boundaries in it */
    public array $trail = [];

    public function __construct(private readonly InMemoryGameRepository $inner) {}

    public function find(GameId $id): ?Game
    {
        $this->trail[] = 'find';

        return $this->inner->find($id);
    }

    public function findForUpdate(GameId $id): ?Game
    {
        $this->trail[] = 'findForUpdate';

        return $this->inner->findForUpdate($id);
    }

    public function findByJoinCode(JoinCode $code): ?Game
    {
        $this->trail[] = 'findByJoinCode';

        return $this->inner->findByJoinCode($code);
    }

    public function transactional(Closure $work): mixed
    {
        $this->trail[] = 'begin';

        try {
            return $this->inner->transactional($work);
        } finally {
            $this->trail[] = 'end';
        }
    }

    public function save(Game $game, ?RequestId $requestId = null): void
    {
        $this->trail[] = 'save';

        $this->inner->save($game, $requestId);
    }

    /**
     * @return array{game: GameId, kind: string}|null
     */
    public function intentionOfRequest(RequestId $requestId): ?array
    {
        $this->trail[] = 'intentionOfRequest';

        return $this->inner->intentionOfRequest($requestId);
    }
}
