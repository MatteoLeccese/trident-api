<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Game\Application\Query\TallyGames\TallyGamesQuery;
use Src\Game\Domain\Model\GameTally;
use Src\Shared\Domain\Bus\QueryBus;

/**
 * Prints how many games the room finished against how many it started.
 *
 * A command and not a route: the API has no authentication of any kind, and a
 * number about every game the product has ever served is not something to hang
 * off an unauthenticated URL because it was convenient. Whoever can reach the
 * container can read it; nobody else can.
 */
final class TallyGamesCommand extends Command
{
    protected $signature = 'trident:tally {--json : Print the raw counts instead of the table}';

    protected $description = 'Counts games that began against games that reached their end.';

    public function handle(QueryBus $queries): int
    {
        /** @var GameTally $tally */
        $tally = $queries->ask(new TallyGamesQuery);
        $counts = $tally->toArray();

        if ($this->option('json')) {
            $this->line((string) json_encode($counts, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['', 'games'], [
            ['Never started (lobby only)', $counts['never_started']],
            ['Started', $counts['started']],
            ['  still on a table', $counts['in_flight']],
            ['  finished', $counts['finished']],
            ['  left behind', $counts['abandoned_idle']],
            ['  closed for a rematch', $counts['abandoned_for_rematch']],
            ['  abandoned, reason unrecorded', $counts['abandoned_unexplained']],
        ]);

        $rate = $tally->completionRate();

        if ($rate === null) {
            // Not 0%: no game has settled is a different answer from every game
            // abandoned, and printing a percentage here would invent the second.
            $this->comment('No game has settled yet, so there is no ratio to report.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d of %d settled games reached their end (%.0f%%).',
            $tally->finished(),
            $tally->settled(),
            $rate * 100,
        ));

        return self::SUCCESS;
    }
}
