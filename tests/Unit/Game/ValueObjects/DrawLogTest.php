<?php

declare(strict_types=1);

namespace Tests\Unit\Game\ValueObjects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Game\Domain\ValueObjects\Draw;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Tile;

final class DrawLogTest extends TestCase
{
    private function draw(string $stage, int $seat, int $position, string $tile): Draw
    {
        return Draw::of($stage, SeatNumber::fromInt($seat), PoolPosition::fromInt($position), Tile::fromString($tile));
    }

    private function log(): DrawLog
    {
        return DrawLog::of([
            $this->draw('election', 1, 12, '05'),
            $this->draw('election', 2, 3, '33'),
            $this->draw('main', 1, 7, '64'),
            $this->draw('main', 2, 1, '05'),
            $this->draw('main', 3, 40, '31'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function faces(DrawLog $log): array
    {
        return array_map(static fn (Tile $tile): string => $tile->value(), $log->tiles());
    }

    public function test_a_fresh_log_is_empty(): void
    {
        $log = DrawLog::empty();

        $this->assertTrue($log->isEmpty());
        $this->assertSame([], $log->all());
        $this->assertNull($log->first());
        $this->assertNull($log->last());
    }

    public function test_appending_returns_a_new_log_and_leaves_the_old_one_alone(): void
    {
        $before = DrawLog::empty();
        $after = $before->append($this->draw('election', 1, 12, '05'));

        $this->assertTrue($before->isEmpty());
        $this->assertFalse($after->isEmpty());
    }

    public function test_it_keeps_the_order_in_which_tiles_were_drawn(): void
    {
        $this->assertSame(['05', '33', '64', '05', '31'], $this->faces($this->log()));
    }

    public function test_a_draw_carries_who_drew_it_where_and_in_which_stage(): void
    {
        $draw = $this->log()->first();

        $this->assertInstanceOf(Draw::class, $draw);
        $this->assertSame('election', $draw->stage());
        $this->assertSame(1, $draw->seat()->value());
        $this->assertSame(12, $draw->position()->value());
        $this->assertSame('05', $draw->tile()->value());
    }

    public function test_it_answers_what_one_person_drew(): void
    {
        // The history the owner asked for: what each person drew, in order.
        $this->assertSame(['05', '64'], $this->faces($this->log()->bySeat(SeatNumber::fromInt(1))));
        $this->assertSame(['31'], $this->faces($this->log()->bySeat(SeatNumber::fromInt(3))));
        $this->assertSame([], $this->faces($this->log()->bySeat(SeatNumber::fromInt(9))));
    }

    public function test_it_answers_the_history_of_one_stage(): void
    {
        // A pool is per stage, so a rule that reads history reads it per stage.
        $this->assertSame(['05', '33'], $this->faces($this->log()->inStage('election')));
        $this->assertSame(['64', '05', '31'], $this->faces($this->log()->inStage('main')));
        $this->assertSame([], $this->faces($this->log()->inStage('nowhere')));
    }

    public function test_filters_compose(): void
    {
        $this->assertSame(['64'], $this->faces($this->log()->inStage('main')->bySeat(SeatNumber::fromInt(1))));
    }

    public function test_the_last_draw_is_the_one_that_just_happened(): void
    {
        $last = $this->log()->last();

        $this->assertInstanceOf(Draw::class, $last);
        $this->assertSame('31', $last->tile()->value());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function tilesAndWhetherTheyHaveComeUp(): array
    {
        return [
            'the double three' => ['33', true],
            'a tile drawn twice, in two stages' => ['05', true],
            'a tile nobody drew' => ['66', false],
            'the mirror of one that was drawn' => ['13', false],
        ];
    }

    #[DataProvider('tilesAndWhetherTheyHaveComeUp')]
    public function test_it_knows_whether_a_tile_has_come_up(string $tile, bool $expected): void
    {
        $this->assertSame($expected, $this->log()->containsTile(Tile::fromString($tile)));
    }

    public function test_it_knows_whether_a_position_has_come_up(): void
    {
        $election = $this->log()->inStage('election');

        $this->assertTrue($election->containsPosition(PoolPosition::fromInt(3)));
        $this->assertFalse($election->containsPosition(PoolPosition::fromInt(7)));
    }

    public function test_it_counts_nothing(): void
    {
        // Nothing in this game is counted: no score, no drinks, no turns played.
        // A log that published a total would be the first counter in the system.
        foreach (['count', 'total', 'score', 'tally', 'turns'] as $forbidden) {
            $this->assertFalse(
                method_exists(DrawLog::class, $forbidden),
                "DrawLog::{$forbidden}() would be a scoreboard.",
            );
        }
    }
}
