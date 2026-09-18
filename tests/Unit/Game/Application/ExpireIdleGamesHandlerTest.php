<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Application;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Src\Game\Application\Command\ExpireIdleGames\ExpireIdleGamesCommand;
use Src\Game\Application\Command\ExpireIdleGames\ExpireIdleGamesHandler;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\GameSnapshot;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\FinishReason;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Doubles\InMemoryGameRepository;
use Tests\Doubles\SpyGameStatePublisher;
use Tests\Support\GameProjectorFactory;

/**
 * The sweep that ends the games a table walked away from.
 *
 * The television is the reason this class exists at all. It holds a socket open
 * all evening and paints whatever last arrived on it, so a game that dies on the
 * server without a broadcast leaves the big screen announcing somebody's turn
 * until the room is empty and the lights are off.
 */
final class ExpireIdleGamesHandlerTest extends TestCase
{
    private const NOW = '2026-09-18 22:00:00';

    /** Three hours, the shipped window. */
    private const WINDOW_MINUTES = 180;

    private InMemoryGameRepository $games;

    private SpyGameStatePublisher $publisher;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->games = new InMemoryGameRepository;
        $this->publisher = new SpyGameStatePublisher;
        $this->clock = FrozenClock::at(self::NOW);
    }

    public function test_it_ends_a_game_nobody_has_touched_since_the_window(): void
    {
        $game = $this->gameLastTouchedAt('2026-09-18 18:30:00');

        $expired = $this->sweep();

        $this->assertCount(1, $expired);
        $this->assertSame(GameStatus::ABANDONED, $this->reload($game)->status());
    }

    public function test_it_says_why_the_game_ended(): void
    {
        // The whole reason the framework reasons exist: a rematch and a walkout
        // are both `abandoned`, and nothing else in the row tells them apart.
        $game = $this->gameLastTouchedAt('2026-09-18 18:30:00');

        $this->sweep();

        $this->assertSame(FinishReason::IDLE_TIMEOUT, $this->reload($game)->finishReason());
    }

    public function test_it_leaves_a_game_that_was_touched_inside_the_window(): void
    {
        $game = $this->gameLastTouchedAt('2026-09-18 21:59:00');

        $this->assertSame([], $this->sweep());
        $this->assertSame(GameStatus::LOBBY, $this->reload($game)->status());
        $this->assertSame([], $this->publisher->published);
    }

    public function test_the_boundary_is_not_idle_yet(): void
    {
        // Exactly at the window is a table that has been quiet for precisely as
        // long as the window allows, which is not longer than it.
        $this->gameLastTouchedAt('2026-09-18 19:00:00');

        $this->assertSame([], $this->sweep());
    }

    public function test_every_expiry_is_published(): void
    {
        // A television reads nothing but what arrives on its socket. An expiry
        // that did not publish would leave the big screen on somebody's turn for
        // a game that no longer exists.
        $game = $this->gameLastTouchedAt('2026-09-18 10:00:00');

        $this->sweep();

        $this->assertCount(1, $this->publisher->published);

        $published = $this->publisher->published[0]->toArray();

        $this->assertSame($game->id()->value(), $published['game_id']);
        $this->assertSame(GameStatus::ABANDONED, $published['status']);

        // A game that has ended releases its code, on this path like every other.
        $this->assertNull($published['join_code']);
    }

    public function test_it_publishes_the_version_the_expiry_produced(): void
    {
        $game = $this->gameLastTouchedAt('2026-09-18 10:00:00');
        $before = $game->version()->value();

        $expired = $this->sweep();

        $this->assertSame($before + 1, $expired[0]->version()->value());
        $this->assertSame($before + 1, $this->reload($game)->version()->value());
    }

    public function test_it_never_touches_a_game_that_has_already_ended(): void
    {
        // Already terminal and long silent: the query excludes it, and if it did
        // not, the aggregate would refuse and the version would say so.
        $game = $this->gameLastTouchedAt('2026-09-18 10:00:00');
        $game->abandon(FrozenClock::at('2026-09-18 10:05:00'), FinishReason::REPLACED_BY_REMATCH);
        $this->games->save($game);

        $this->assertSame([], $this->sweep());
        $this->assertSame([], $this->publisher->published);

        // And the reason it ended with is the true one, not the sweep's.
        $this->assertSame(FinishReason::REPLACED_BY_REMATCH, $this->reload($game)->finishReason());
    }

    public function test_it_ends_the_oldest_games_first_and_stops_at_its_limit(): void
    {
        // A sweep that cannot reach the end of a backlog takes the games that
        // have been dead longest, so a scheduler that was down for a week catches
        // up in order instead of starving the same rows every tick.
        $oldest = $this->gameLastTouchedAt('2026-09-17 08:00:00');
        $middle = $this->gameLastTouchedAt('2026-09-17 12:00:00');
        $newest = $this->gameLastTouchedAt('2026-09-17 16:00:00');

        $expired = $this->sweep(limit: 2);

        $this->assertSame(
            [$oldest->id()->value(), $middle->id()->value()],
            array_map(static fn ($snapshot): string => $snapshot->gameId()->value(), $expired),
        );

        $this->assertSame(GameStatus::LOBBY, $this->reload($newest)->status());
    }

    public function test_a_sweep_that_finds_nothing_ends_nothing_and_says_nothing(): void
    {
        $this->assertSame([], $this->sweep());
        $this->assertSame([], $this->publisher->published);
    }

    public function test_it_ends_a_game_that_was_being_played(): void
    {
        // Not only lobbies. A table that walks out mid-game is the case the
        // television is left showing a turn for.
        $game = $this->gameLastTouchedAt('2026-09-18 10:00:00');
        $game->start(new TridentRuleSet, FrozenClock::at('2026-09-18 10:01:00'));
        $this->games->save($game);

        $this->assertSame(GameStatus::RUNNING, $this->reload($game)->status());

        $expired = $this->sweep();

        $this->assertCount(1, $expired);
        $this->assertSame(GameStatus::ABANDONED, $this->reload($game)->status());
    }

    public function test_it_refuses_a_window_or_a_limit_that_would_expire_everything(): void
    {
        // A zero window expires every game the product has ever served, including
        // the one on the table. It is refused where it is cheapest to refuse.
        $this->expectException(InvalidArgumentException::class);

        $this->handler()->handle(new ExpireIdleGamesCommand(0, 10));
    }

    public function test_it_refuses_a_limit_of_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->handler()->handle(new ExpireIdleGamesCommand(self::WINDOW_MINUTES, 0));
    }

    /**
     * @return list<GameSnapshot>
     */
    private function sweep(int $limit = 100): array
    {
        return $this->handler()->handle(new ExpireIdleGamesCommand(self::WINDOW_MINUTES, $limit));
    }

    private function handler(): ExpireIdleGamesHandler
    {
        return new ExpireIdleGamesHandler(
            $this->games,
            $this->publisher,
            GameProjectorFactory::make(),
            $this->clock,
        );
    }

    /** A saved game whose last write happened at the given instant. */
    private function gameLastTouchedAt(string $instant): Game
    {
        $game = Game::open(
            GameId::random(),
            JoinCode::fromString('K7QP3M'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at($instant),
        );

        $this->games->save($game);

        return $game;
    }

    private function reload(Game $game): Game
    {
        $found = $this->games->find($game->id());

        $this->assertInstanceOf(Game::class, $found);

        return $found;
    }
}
