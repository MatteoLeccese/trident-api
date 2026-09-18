<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Realtime\Infrastructure\Broadcasting\GameStateChanged;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\TestCase;

/**
 * The sweep as it actually runs: through the console, the bus and a real
 * database.
 *
 * The handler's own behaviour is asserted without a database elsewhere. What is
 * asserted here is the wiring, which is the half that fails silently: a command
 * nobody registered, a handler the bus cannot find, a query the engine refuses,
 * and — the one that would leave every abandoned game on its television for ever
 * — a schedule with nothing in it.
 */
final class ExpireGamesCommandTest extends TestCase
{
    use RefreshDatabase;

    private static int $minted = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$minted = 0;
    }

    public function test_it_ends_a_game_nobody_has_written_to_for_longer_than_the_window(): void
    {
        $game = $this->gameLastTouchedAt('2000-01-01 00:00:00');

        $this->artisan('trident:expire-games')
            ->expectsOutputToContain('Ended 1 game(s)')
            ->assertSuccessful();

        $this->assertSame(GameStatus::ABANDONED, $this->reload($game)->status());
        $this->assertSame(FinishReason::IDLE_TIMEOUT, $this->reload($game)->finishReason());
    }

    public function test_it_tells_the_television_on_the_socket_it_is_already_holding(): void
    {
        Event::fake([GameStateChanged::class]);

        $this->gameLastTouchedAt('2000-01-01 00:00:00');

        $this->artisan('trident:expire-games')->assertSuccessful();

        Event::assertDispatched(GameStateChanged::class);
    }

    public function test_a_game_written_to_recently_is_left_alone(): void
    {
        $game = $this->gameLastTouchedAt('now');

        $this->artisan('trident:expire-games')
            ->expectsOutputToContain('No game has been idle')
            ->assertSuccessful();

        $this->assertSame(GameStatus::LOBBY, $this->reload($game)->status());
    }

    public function test_the_window_can_be_overridden_for_one_run(): void
    {
        // What somebody standing at the stack actually needs: end this one now,
        // without editing a configuration file and restarting the container.
        $game = $this->gameLastTouchedAt('-2 minutes');

        $this->artisan('trident:expire-games', ['--minutes' => 1])->assertSuccessful();

        $this->assertSame(GameStatus::ABANDONED, $this->reload($game)->status());
    }

    public function test_it_refuses_a_window_that_would_end_every_game_on_every_table(): void
    {
        $game = $this->gameLastTouchedAt('now');

        $this->artisan('trident:expire-games', ['--minutes' => 0])->assertFailed();

        $this->assertSame(GameStatus::LOBBY, $this->reload($game)->status());
    }

    public function test_it_says_out_loud_when_it_stopped_at_its_limit(): void
    {
        // A sweep that stopped short has not finished the backlog, and a round
        // number in the output is not a thing anybody notices at two in the
        // morning.
        $this->gameLastTouchedAt('2000-01-01 00:00:00');
        $this->gameLastTouchedAt('2000-01-01 00:00:00');

        $this->artisan('trident:expire-games', ['--limit' => 1])
            ->expectsOutputToContain('stopped at its limit')
            ->assertSuccessful();
    }

    public function test_something_actually_runs_it(): void
    {
        /*
         * The failure this closes is the quietest one in the product: a command
         * that works perfectly and that nothing ever calls. Without a schedule
         * entry, every game a table walks away from stays `running` for ever and
         * its television keeps announcing somebody's turn.
         */
        // The schedule is registered when the console application starts, not
        // when the container is built, so resolving it cold answers an empty
        // list and this test would pass on a product that schedules nothing.
        Artisan::call('list');

        $commands = array_map(
            static fn (object $event): string => (string) $event->command,
            $this->app->make(Schedule::class)->events(),
        );

        $scheduled = array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, 'trident:expire-games'),
        );

        $this->assertCount(1, $scheduled, 'The sweep is scheduled exactly once: twice would run it twice a tick.');
    }

    private function gameLastTouchedAt(string $instant): Game
    {
        $game = Game::open(
            GameId::random(),
            JoinCode::fromString(sprintf('K7QP%02d', ++self::$minted)),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at($instant),
        );

        $this->app->make(GameRepository::class)->save($game);

        return $game;
    }

    private function reload(Game $game): Game
    {
        $found = $this->app->make(GameRepository::class)->find($game->id());

        $this->assertInstanceOf(Game::class, $found);

        return $found;
    }
}
