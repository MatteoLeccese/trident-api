<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use InvalidArgumentException;

/**
 * How many games the product has served, and how they ended.
 *
 * For a party game this is the only number that matters, and it is one ratio:
 * **games that began against games that reached their end.** A room that starts
 * five and finishes one is a room that got bored, and no amount of green tests
 * says so.
 *
 * It is derived and stores nothing of its own. "Began" is a game pinned to a
 * ruleset, which happens exactly once and only at `start()`; "finished" is the
 * terminal status the rules produce. Neither needed a column, and a column that
 * repeated either would be a second copy that has to agree with the first.
 *
 * The two abandoned counts are the reason `FinishReason` gained its framework
 * members. Without them a rematch and a walkout are the same row, and the ratio
 * this class exists for would count every table that kept playing as a table
 * that left.
 */
final class GameTally
{
    private function __construct(
        private readonly int $neverStarted,
        private readonly int $started,
        private readonly int $finished,
        private readonly int $abandonedIdle,
        private readonly int $abandonedForRematch,
        private readonly int $abandonedUnexplained,
        private readonly int $inFlight,
    ) {}

    public static function of(
        int $neverStarted,
        int $started,
        int $finished,
        int $abandonedIdle,
        int $abandonedForRematch,
        int $abandonedUnexplained,
        int $inFlight,
    ): self {
        foreach (func_get_args() as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('A tally counts nothing below zero.');
            }
        }

        return new self(
            $neverStarted,
            $started,
            $finished,
            $abandonedIdle,
            $abandonedForRematch,
            $abandonedUnexplained,
            $inFlight,
        );
    }

    /** Lobbies where nobody ever tapped start. */
    public function neverStarted(): int
    {
        return $this->neverStarted;
    }

    /** Games where play began, whatever became of them afterwards. */
    public function started(): int
    {
        return $this->started;
    }

    /** Games that reached their own end. */
    public function finished(): int
    {
        return $this->finished;
    }

    /** Games the table walked away from. */
    public function abandonedIdle(): int
    {
        return $this->abandonedIdle;
    }

    /** Games closed because the table asked for another one — a room still playing. */
    public function abandonedForRematch(): int
    {
        return $this->abandonedForRematch;
    }

    /**
     * Games abandoned before any of this was recorded, carrying no reason.
     *
     * It exists so the arithmetic stays honest across the change that introduced
     * the reasons rather than quietly folding history into one of the two
     * meanings.
     */
    public function abandonedUnexplained(): int
    {
        return $this->abandonedUnexplained;
    }

    /** Games still on a table right now. */
    public function inFlight(): int
    {
        return $this->inFlight;
    }

    /**
     * Games that began and are no longer being played: the denominator of the
     * only ratio anybody will ask for.
     */
    public function settled(): int
    {
        return $this->finished + $this->abandonedIdle + $this->abandonedForRematch + $this->abandonedUnexplained;
    }

    /**
     * The share of settled games that reached their end, or null when none has
     * settled yet.
     *
     * Null rather than zero: no games played is not the same answer as every
     * game abandoned, and a dashboard that printed 0% on an empty table would be
     * the second-worst thing this number could do.
     */
    public function completionRate(): ?float
    {
        $settled = $this->settled();

        return $settled === 0 ? null : $this->finished / $settled;
    }

    /**
     * @return array{never_started: int, started: int, finished: int, abandoned_idle: int, abandoned_for_rematch: int, abandoned_unexplained: int, in_flight: int, settled: int, completion_rate: float|null}
     */
    public function toArray(): array
    {
        return [
            'never_started' => $this->neverStarted,
            'started' => $this->started,
            'finished' => $this->finished,
            'abandoned_idle' => $this->abandonedIdle,
            'abandoned_for_rematch' => $this->abandonedForRematch,
            'abandoned_unexplained' => $this->abandonedUnexplained,
            'in_flight' => $this->inFlight,
            'settled' => $this->settled(),
            'completion_rate' => $this->completionRate(),
        ];
    }
}
