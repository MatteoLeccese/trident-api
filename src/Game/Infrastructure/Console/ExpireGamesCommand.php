<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Game\Application\Command\ExpireIdleGames\ExpireIdleGamesCommand;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Shared\Domain\Bus\CommandBus;

/**
 * Ends the games the table walked away from.
 *
 * It is the only reader of `trident.idle_timeout_minutes`: the window is a
 * per-deployment value and neither the domain nor the handler may read a
 * configuration file, so it is read here and handed over.
 *
 * Scheduled, but safe to run by hand at any time. A sweep that finds nothing
 * ends nothing, and a game it ends is already terminal for the next one.
 */
final class ExpireGamesCommand extends Command
{
    protected $signature = 'trident:expire-games
        {--limit=200 : The most games one sweep will end}
        {--minutes= : Override the configured window, in minutes}';

    protected $description = 'Abandons the games nobody has written to for longer than the idle window, and tells their televisions.';

    public function handle(CommandBus $commands): int
    {
        $minutes = $this->option('minutes') === null
            ? (int) config('trident.idle_timeout_minutes')
            : (int) $this->option('minutes');

        $limit = (int) $this->option('limit');

        if ($minutes < 1 || $limit < 1) {
            $this->error('The window and the limit are both positive numbers of minutes and games.');

            return self::FAILURE;
        }

        /** @var list<GameSnapshot> $expired */
        $expired = $commands->dispatch(new ExpireIdleGamesCommand($minutes, $limit));

        if ($expired === []) {
            $this->info("No game has been idle for {$minutes} minutes.");

            return self::SUCCESS;
        }

        $this->info(sprintf('Ended %d game(s) idle for more than %d minutes:', count($expired), $minutes));

        foreach ($expired as $snapshot) {
            $this->line('  '.$snapshot->gameId()->value().'  version '.$snapshot->version()->value());
        }

        if (count($expired) === $limit) {
            // Said out loud rather than left to be inferred from a round number:
            // a sweep that stopped at its limit has not finished the backlog.
            $this->comment("The sweep stopped at its limit of {$limit}; run it again to continue.");
        }

        return self::SUCCESS;
    }
}
