<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Src\Game\Domain\Model\GameTally;
use Src\Game\Domain\Repository\GameTallyReader;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\ValueObjects\GameStatus;

/**
 * The tally, as one pass over `games`.
 *
 * One grouped read and not seven counts: seven statements over a growing table
 * would answer from seven different instants, and the arithmetic the value
 * object does — settled, completion rate — would then be over numbers that never
 * coexisted.
 *
 * Every term is derived from columns that already exist. `rule_set_id` is
 * written exactly once, at `start()`, and never cleared, so "a game where play
 * began" needs no column of its own; the rest is `status` and `finish_reason`.
 */
final class EloquentGameTallyReader implements GameTallyReader
{
    public function tally(): GameTally
    {
        /** @var list<object{status: string, finish_reason: string|null, started: int|string|bool, total: int|string}> $rows */
        $rows = DB::table('games')
            ->selectRaw('status, finish_reason, (rule_set_id is not null) as started, count(*) as total')
            ->groupBy('status', 'finish_reason', 'started')
            ->get()
            ->all();

        $counts = [
            'never_started' => 0,
            'started' => 0,
            'finished' => 0,
            'abandoned_idle' => 0,
            'abandoned_for_rematch' => 0,
            'abandoned_unexplained' => 0,
            'in_flight' => 0,
        ];

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $status = (string) $row->status;

            // Postgres answers a boolean and SQLite answers 0/1: the cast is what
            // makes one branch serve both engines.
            if (! (bool) $row->started) {
                $counts['never_started'] += $total;

                continue;
            }

            $counts['started'] += $total;

            if (! GameStatus::isTerminal($status)) {
                $counts['in_flight'] += $total;

                continue;
            }

            if ($status === GameStatus::FINISHED) {
                $counts['finished'] += $total;

                continue;
            }

            $counts[match ($row->finish_reason) {
                FinishReason::IDLE_TIMEOUT => 'abandoned_idle',
                FinishReason::REPLACED_BY_REMATCH => 'abandoned_for_rematch',
                default => 'abandoned_unexplained',
            }] += $total;
        }

        return GameTally::of(
            $counts['never_started'],
            $counts['started'],
            $counts['finished'],
            $counts['abandoned_idle'],
            $counts['abandoned_for_rematch'],
            $counts['abandoned_unexplained'],
            $counts['in_flight'],
        );
    }
}
