<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameTally;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Repository\GameTallyReader;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\TestCase;

/**
 * The one number the product is judged by, read against a real database.
 *
 * It is derived and stores nothing, so the only thing that can break it is the
 * derivation disagreeing with what the aggregate actually writes — which is
 * exactly what a test against a double could not see. Every game below is built
 * by playing it, never by inserting a row.
 *
 * The engine matters here too: `rule_set_id is not null` comes back as a boolean
 * from PostgreSQL and as 0/1 from SQLite, and this suite runs on both.
 */
final class GameTallyTest extends TestCase
{
    use RefreshDatabase;

    /** A literal shuffle seed: a tally that depends on chance proves nothing. */
    private const SEED = 'ZbVQ8vUCcVNJNCYLbhwSEhz1vmKpSiIk0WBlGzHU7Ss';

    /** Counts the join codes handed out, so no two games in one test collide. */
    private static int $minted = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$minted = 0;
    }

    public function test_an_empty_product_has_served_nobody(): void
    {
        $tally = $this->tally();

        $this->assertSame(0, $tally->started());
        $this->assertSame(0, $tally->finished());
        $this->assertSame(0, $tally->settled());

        // Not 0%: no game played is a different answer from every game
        // abandoned, and a dashboard that printed zero here would invent the
        // second one out of the first.
        $this->assertNull($tally->completionRate());
    }

    public function test_a_lobby_nobody_started_is_not_a_game_that_began(): void
    {
        $this->lobby();

        $tally = $this->tally();

        $this->assertSame(1, $tally->neverStarted());
        $this->assertSame(0, $tally->started());
        $this->assertNull($tally->completionRate());
    }

    public function test_a_game_on_a_table_has_begun_and_has_not_settled(): void
    {
        $this->started();

        $tally = $this->tally();

        $this->assertSame(1, $tally->started());
        $this->assertSame(1, $tally->inFlight());
        $this->assertSame(0, $tally->settled());
        $this->assertNull($tally->completionRate());
    }

    public function test_it_counts_a_game_played_to_its_end(): void
    {
        $this->playedToTheEnd();

        $tally = $this->tally();

        $this->assertSame(1, $tally->started());
        $this->assertSame(1, $tally->finished());
        $this->assertSame(0, $tally->inFlight());
        $this->assertSame(1.0, $tally->completionRate());
    }

    public function test_it_tells_a_room_that_left_from_a_room_that_kept_playing(): void
    {
        // The whole point of the two framework reasons. Both games below are
        // `abandoned` and nothing else in the row separates them: counted
        // together, a table that played all night reads exactly like a table that
        // walked out after one game.
        $left = $this->started();
        $left->abandon(FrozenClock::at('2026-09-18 23:00:00'), FinishReason::IDLE_TIMEOUT);
        $this->games()->save($left);

        $again = $this->started();
        $again->abandon(FrozenClock::at('2026-09-18 23:00:00'), FinishReason::REPLACED_BY_REMATCH);
        $this->games()->save($again);

        $tally = $this->tally();

        $this->assertSame(2, $tally->started());
        $this->assertSame(1, $tally->abandonedIdle());
        $this->assertSame(1, $tally->abandonedForRematch());
        $this->assertSame(0, $tally->abandonedUnexplained());
        $this->assertSame(2, $tally->settled());
        $this->assertSame(0.0, $tally->completionRate());
    }

    public function test_the_ratio_is_over_settled_games_and_not_over_every_game_ever_opened(): void
    {
        // A lobby nobody started and a game still on a table are not failures to
        // finish: counting them against the room would make the number drop every
        // time somebody opens the app.
        $this->lobby();
        $this->started();
        $this->playedToTheEnd();

        $walked = $this->started();
        $walked->abandon(FrozenClock::at('2026-09-18 23:00:00'), FinishReason::IDLE_TIMEOUT);
        $this->games()->save($walked);

        $tally = $this->tally();

        $this->assertSame(1, $tally->neverStarted());
        $this->assertSame(3, $tally->started());
        $this->assertSame(1, $tally->inFlight());
        $this->assertSame(2, $tally->settled());
        $this->assertSame(0.5, $tally->completionRate());
    }

    public function test_the_whole_shape_travels_as_one_map(): void
    {
        $this->playedToTheEnd();

        $this->assertSame(
            [
                'never_started' => 0,
                'started' => 1,
                'finished' => 1,
                'abandoned_idle' => 0,
                'abandoned_for_rematch' => 0,
                'abandoned_unexplained' => 0,
                'in_flight' => 0,
                'settled' => 1,
                'completion_rate' => 1.0,
            ],
            $this->tally()->toArray(),
        );
    }

    public function test_the_console_command_prints_the_ratio(): void
    {
        $this->playedToTheEnd();

        $this->artisan('trident:tally')
            ->expectsOutputToContain('1 of 1 settled games reached their end (100%).')
            ->assertSuccessful();
    }

    public function test_the_console_command_says_nothing_has_settled_rather_than_printing_zero(): void
    {
        $this->started();

        $this->artisan('trident:tally')
            ->expectsOutputToContain('No game has settled yet')
            ->assertSuccessful();
    }

    private function tally(): GameTally
    {
        return $this->app->make(GameTallyReader::class)->tally();
    }

    private function games(): GameRepository
    {
        return $this->app->make(GameRepository::class);
    }

    private function lobby(): Game
    {
        $game = Game::open(
            GameId::random(),
            // Distinct and deterministic: the column is unique, and a random code
            // would make this suite fail once in a while for a reason that has
            // nothing to do with what it asserts.
            JoinCode::fromString(sprintf('K7QP%02d', ++self::$minted)),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-18 20:00:00'),
            Seed::fromString(self::SEED),
        );

        $this->games()->save($game);

        return $game;
    }

    private function started(): Game
    {
        $game = $this->lobby();
        $game->start(new TridentRuleSet, FrozenClock::at('2026-09-18 20:01:00'));
        $this->games()->save($game);

        return $game;
    }

    /**
     * A game driven to its own end, the way one actually ends: the election to
     * the double three and then `main` until its pool runs out.
     */
    private function playedToTheEnd(): Game
    {
        $game = $this->started();
        $rules = new TridentRuleSet;
        $step = 0;

        while (! GameStatus::isTerminal($game->status())) {
            $game->drawTile(
                PoolPosition::fromInt($this->nextPosition($game)),
                $rules,
                FrozenClock::at(sprintf('2026-09-18 21:%02d:%02d', intdiv($step, 60), $step % 60)),
            );

            $step++;

            $this->assertLessThan(120, $step, 'A game should have ended long before here.');
        }

        $this->games()->save($game);

        return $game;
    }

    /** The lowest position of the current stage's pool that nobody has taken. */
    private function nextPosition(Game $game): int
    {
        foreach ($game->projectPool(new TridentRuleSet) as $position) {
            if ($position['taken'] === false) {
                return (int) $position['position'];
            }
        }

        $this->fail('The pool has no position left to take.');
    }
}
