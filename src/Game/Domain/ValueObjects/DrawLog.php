<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

/**
 * What has been drawn so far, in order.
 *
 * **It has no column.** It is reconstituted from the `tile_drawn` rows of
 * `game_moves`, which is a record and not a score, and which dies with the game
 * (TR-55). A rule reads it to decide, so it travels in `GameContext` and
 * `DrawContext` — and it is a log rather than a tally on purpose: nothing in
 * this game is counted (TR-54), so this class publishes no total of draws, of
 * turns, or of anything else. A rule that needs history reads the history.
 *
 * Immutable: `append()` returns the next log.
 */
final class DrawLog
{
    /**
     * @param  list<Draw>  $draws
     */
    private function __construct(private readonly array $draws) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  list<Draw>  $draws  oldest first
     */
    public static function of(array $draws): self
    {
        return new self(array_values($draws));
    }

    public function append(Draw $draw): self
    {
        return new self([...$this->draws, $draw]);
    }

    /**
     * @return list<Draw>
     */
    public function all(): array
    {
        return $this->draws;
    }

    public function isEmpty(): bool
    {
        return $this->draws === [];
    }

    public function first(): ?Draw
    {
        return $this->draws[0] ?? null;
    }

    public function last(): ?Draw
    {
        return $this->draws[count($this->draws) - 1] ?? null;
    }

    /** The history of one stage. A pool is per stage (TR-04), and so is its history. */
    public function inStage(string $stage): self
    {
        return $this->filter(static fn (Draw $draw): bool => $draw->stage() === $stage);
    }

    /** What one person drew. */
    public function bySeat(SeatNumber $seat): self
    {
        return $this->filter(static fn (Draw $draw): bool => $draw->seat()->equals($seat));
    }

    public function containsTile(Tile $tile): bool
    {
        foreach ($this->draws as $draw) {
            if ($draw->tile()->equals($tile)) {
                return true;
            }
        }

        return false;
    }

    public function containsPosition(PoolPosition $position): bool
    {
        foreach ($this->draws as $draw) {
            if ($draw->position()->equals($position)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Tile>
     */
    public function tiles(): array
    {
        return array_map(static fn (Draw $draw): Tile => $draw->tile(), $this->draws);
    }

    /**
     * @param  callable(Draw): bool  $keep
     */
    private function filter(callable $keep): self
    {
        return new self(array_values(array_filter($this->draws, $keep)));
    }
}
