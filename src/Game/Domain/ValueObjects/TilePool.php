<?php

declare(strict_types=1);

namespace Src\Game\Domain\ValueObjects;

use Src\Game\Domain\Exceptions\PoolPositionAlreadyTakenException;
use Src\Game\Domain\Exceptions\PoolPositionNotInPoolException;
use Src\Game\Domain\Rules\BoardPresence;
use Src\Game\Domain\Rules\Visibility;

/**
 * A stage's deck, materialised: an ordered, 1-based list of positions, each
 * holding a tile and each either untaken or taken by one seat.
 *
 * A stage builds its own pool from a fresh deck (TR-04), so the identity of a
 * draw is `(stage, position)` and never the tile.
 *
 * Immutable: `take()` returns the next pool. A taken position is marked and
 * never removed (TR-08) — removing it would renumber every position after it,
 * and a stale phone touching position 7 would draw a different tile.
 *
 * The taker of a position is **framework bookkeeping and not a rule**. It is
 * held here and emitted by `project()` so that a television can show who filled
 * the board without counting a pip, and it is read by the repository through
 * `takers()`. There is no per-position accessor for it, because the one thing a
 * rule reads about what has already happened is the `DrawLog`: that log spans
 * the whole game, while this map is emptied at every stage change (TR-04), so
 * two answers to "who took position 7" would disagree the moment a stage ends.
 *
 * The pool itself does reach a rule, as `DrawContext::poolBefore()`, and
 * `takers()` is public on it. Nothing in the seam is expected to call it and
 * nothing does; the guarantee this class gives is the `DrawLog`'s, and a rule
 * that reached past it for the taker would be reading a stage-scoped answer to a
 * game-scoped question.
 *
 * The server holds every face (TR-09); hiding happens in `project()`.
 */
final class TilePool
{
    /**
     * @param  list<Tile>  $tiles
     * @param  array<int, SeatNumber>  $taken  the taker keyed by position, so membership is a lookup
     */
    private function __construct(private readonly array $tiles, private readonly array $taken) {}

    public static function fromDeck(TileDeck $deck, Seed $seed, string $stream): self
    {
        return new self(SeededShuffle::permute($deck->tiles(), $seed, $stream), []);
    }

    /**
     * Reconstruction from persistence. The repository uses it; nothing else.
     *
     * The takers arrive keyed by position rather than as a list of pairs: this is
     * the shape the pool holds, so a reloaded pool is identical to the one that
     * was saved and no caller has to know the order they were stored in.
     *
     * @param  list<Tile>  $tiles  in pool order: the first is position 1
     * @param  array<int, SeatNumber>  $takers  keyed by position
     */
    public static function reconstitute(array $tiles, array $takers): self
    {
        $tiles = array_values($tiles);
        $taken = [];

        foreach ($takers as $position => $seat) {
            if ($position < PoolPosition::FIRST || $position > count($tiles)) {
                throw new PoolPositionNotInPoolException("Position {$position} is not in this pool.");
            }

            $taken[$position] = $seat;
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

    /**
     * One position turned over by one seat.
     *
     * The seat is the `current_seat` the aggregate attributes the draw to
     * (TR-11); the pool never learns it from a client.
     */
    public function take(PoolPosition $position, SeatNumber $seat): self
    {
        if ($this->isTaken($position)) {
            throw new PoolPositionAlreadyTakenException("Position {$position->value()} has already been taken.");
        }

        return new self($this->tiles, $this->taken + [$position->value() => $seat]);
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
     * Who took what, keyed by position and ascending by position. For persistence.
     *
     * The order is imposed here so that two games in the same state persist the
     * same bytes whatever order the table played them in.
     *
     * @return array<int, SeatNumber>
     */
    public function takers(): array
    {
        $takers = $this->taken;

        ksort($takers);

        return $takers;
    }

    /**
     * The pool as the phone and the television both read it.
     *
     * One projection for everybody (TR-06): hiding is a property of the stage and
     * never of the viewer, so the two clients receive the same bytes. An untaken
     * position carries `tile: null` — an explicit null and never an absent key —
     * unless the stage's visibility opens the board (TR-07).
     *
     * The visibility is one of `Visibility::ALL`, answered per stage by
     * `RuleSet::visibility()`. The comparison is fail-closed: anything that is
     * not `Visibility::FACES_OPEN` hides, so a typo in a future ruleset cannot
     * publish 49 faces to a television.
     *
     * `seat` is the seat that took the position, and null while nobody has: it is
     * what lets a television show who filled the board. A taken position's face
     * is already public (TR-08), so this reveals nothing the same entry does not.
     *
     * `on_board` is one of `BoardPresence::ALL`, answered per stage by
     * `RuleSet::boardPresence()`, resolved to a flag per position so that no
     * client has to name the setting behind it. An untaken position is always on
     * the board; a taken one leaves it only under
     * `BoardPresence::TAKEN_LEAVES_BOARD`. The comparison runs the other way
     * round from the visibility above, and deliberately: an unrecognised value
     * keeps the board as it is, because blanking a board on a typo would hide
     * exactly what the screen exists to show, while nothing is disclosed by
     * drawing a tile everybody has already seen.
     *
     * @return list<array{position: int, tile: string|null, taken: bool, seat: int|null, on_board: bool}>
     */
    public function project(string $visibility, string $boardPresence): array
    {
        $open = $visibility === Visibility::FACES_OPEN;
        $leaves = $boardPresence === BoardPresence::TAKEN_LEAVES_BOARD;
        $pool = [];

        foreach ($this->tiles as $index => $tile) {
            $position = $index + 1;
            $seat = $this->taken[$position] ?? null;
            $taken = $seat !== null;

            $pool[] = [
                'position' => $position,
                'tile' => $taken || $open ? $tile->value() : null,
                'taken' => $taken,
                'seat' => $seat?->value(),
                'on_board' => ! ($taken && $leaves),
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
