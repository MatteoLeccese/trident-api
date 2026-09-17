<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use Src\Game\Domain\Exceptions\PoolPositionAlreadyTakenException;
use Src\Game\Domain\Exceptions\PoolPositionNotInPoolException;

/**
 * A stage's deck, materialised: an ordered, 1-based list of positions, each
 * holding a tile and each either untaken or taken.
 *
 * A stage builds its own pool from a fresh deck (TR-04), so the identity of a
 * draw is `(stage, position)` and never the tile.
 *
 * Immutable: `take()` returns the next pool. A taken position is marked and
 * never removed (TR-08) — removing it would renumber every position after it,
 * and a stale phone touching position 7 would draw a different tile.
 *
 * The server holds every face (TR-09); hiding happens in `project()`.
 */
final class TilePool
{
    /**
     * The one visibility under which an untaken position projects its face.
     *
     * The vocabulary belongs to `RuleSet::visibility()`, which does not exist
     * yet; until it does, `project()` takes the raw string and compares it here.
     * Anything else hides, so an unrecognised visibility cannot open the board.
     */
    public const FACES_OPEN = 'faces_open';

    /**
     * @param  list<Tile>  $tiles
     * @param  array<int, true>  $taken  keyed by position, so membership is a lookup
     */
    private function __construct(private readonly array $tiles, private readonly array $taken) {}

    public static function fromDeck(TileDeck $deck, Seed $seed, string $stream): self
    {
        return new self(SeededShuffle::permute($deck->tiles(), $seed, $stream), []);
    }

    /**
     * Reconstruction from persistence. The repository uses it; nothing else.
     *
     * @param  list<Tile>  $tiles  in pool order: the first is position 1
     * @param  list<int>  $takenPositions
     */
    public static function reconstitute(array $tiles, array $takenPositions): self
    {
        $tiles = array_values($tiles);
        $taken = [];

        foreach ($takenPositions as $position) {
            if ($position < PoolPosition::FIRST || $position > count($tiles)) {
                throw new PoolPositionNotInPoolException("Position {$position} is not in this pool.");
            }

            $taken[$position] = true;
        }

        return new self($tiles, $taken);
    }

    public function count(): int
    {
        return count($this->tiles);
    }

    public function has(PoolPosition $position): bool
    {
        return $position->value() <= count($this->tiles);
    }

    /** The face the server holds, whether or not the position has been taken. */
    public function at(PoolPosition $position): Tile
    {
        $this->assertInPool($position);

        return $this->tiles[$position->value() - 1];
    }

    public function isTaken(PoolPosition $position): bool
    {
        $this->assertInPool($position);

        return isset($this->taken[$position->value()]);
    }

    public function take(PoolPosition $position): self
    {
        if ($this->isTaken($position)) {
            throw new PoolPositionAlreadyTakenException("Position {$position->value()} has already been taken.");
        }

        return new self($this->tiles, $this->taken + [$position->value() => true]);
    }

    public function remaining(): int
    {
        return count($this->tiles) - count($this->taken);
    }

    /** Exhaustion is what ends a stage whose rules say so (TR-34). */
    public function isExhausted(): bool
    {
        return $this->remaining() === 0;
    }

    /**
     * Every face, in pool order. For persistence.
     *
     * @return list<Tile>
     */
    public function tiles(): array
    {
        return $this->tiles;
    }

    /**
     * @return list<int>
     */
    public function takenPositions(): array
    {
        $positions = array_keys($this->taken);

        sort($positions);

        return $positions;
    }

    /**
     * The pool as the phone and the television both read it.
     *
     * One projection for everybody (TR-06): hiding is a property of the stage and
     * never of the viewer, so the two clients receive the same bytes. An untaken
     * position carries `tile: null` — an explicit null and never an absent key —
     * unless the stage's visibility opens the board (TR-07).
     *
     * @return list<array{position: int, tile: string|null, taken: bool}>
     */
    public function project(string $visibility): array
    {
        $open = $visibility === self::FACES_OPEN;
        $pool = [];

        foreach ($this->tiles as $index => $tile) {
            $position = $index + 1;
            $taken = isset($this->taken[$position]);

            $pool[] = [
                'position' => $position,
                'tile' => $taken || $open ? $tile->value() : null,
                'taken' => $taken,
            ];
        }

        return $pool;
    }

    private function assertInPool(PoolPosition $position): void
    {
        if (! $this->has($position)) {
            throw new PoolPositionNotInPoolException("Position {$position->value()} is not in this pool.");
        }
    }
}
