<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Application;

use PHPUnit\Framework\TestCase;
use Src\Game\Application\Command\CreateGame\CreateGameCommand;
use Src\Game\Application\Command\CreateGame\CreateGameHandler;
use Src\Game\Application\Command\RenameSeat\RenameSeatCommand;
use Src\Game\Application\Command\RenameSeat\RenameSeatHandler;
use Src\Game\Application\Query\GetGameState\GetGameStateHandler;
use Src\Game\Application\Query\GetGameState\GetGameStateQuery;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeHandler;
use Src\Game\Application\Query\ResolveJoinCode\ResolveJoinCodeQuery;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Exceptions\NicknameTakenException;
use Src\Game\Domain\Exceptions\SeatNotFoundException;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Doubles\InMemoryGameRepository;
use Tests\Doubles\SpyGameStatePublisher;

final class RenameSeatHandlerTest extends TestCase
{
    private InMemoryGameRepository $games;

    private SpyGameStatePublisher $publisher;

    private string $gameId;

    private string $joinCode;

    protected function setUp(): void
    {
        $this->games = new InMemoryGameRepository;
        $this->publisher = new SpyGameStatePublisher;

        $created = new CreateGameHandler(
            $this->games,
            $this->publisher,
            FrozenClock::at('2026-09-16 20:00:00'),
        )->handle(new CreateGameCommand(['Ana', 'Bea', 'Caro']));

        $this->gameId = $created->snapshot->toArray()['game_id'];
        $this->joinCode = $created->snapshot->toArray()['join_code'];
        $this->publisher->published = [];
    }

    private function rename(int $seat, string $nickname, ?string $gameId = null): mixed
    {
        return new RenameSeatHandler(
            $this->games,
            $this->publisher,
            FrozenClock::at('2026-09-16 20:30:00'),
        )->handle(new RenameSeatCommand($gameId ?? $this->gameId, $seat, $nickname));
    }

    public function test_it_renames_the_seat_and_bumps_the_version(): void
    {
        $snapshot = $this->rename(2, 'Bea María')->toArray();

        $this->assertSame('Bea María', $snapshot['seats'][1]['nickname']);
        $this->assertSame(2, $snapshot['version']);
    }

    public function test_the_change_survives_a_reload(): void
    {
        $this->rename(2, 'Bea María');

        $reloaded = $this->games->find(GameId::fromString($this->gameId));

        $this->assertNotNull($reloaded);
        $this->assertSame('Bea María', $reloaded->snapshot()->toArray()['seats'][1]['nickname']);
    }

    public function test_it_publishes_the_new_state_so_the_television_follows(): void
    {
        $this->rename(2, 'Bea María');

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame(2, $this->publisher->published[0]->toArray()['version']);
    }

    public function test_it_slides_the_activity_window(): void
    {
        $snapshot = $this->rename(2, 'Bea María')->toArray();

        $this->assertSame('2026-09-16T20:30:00+00:00', $snapshot['last_activity_at']);
    }

    public function test_an_unknown_game_is_not_found(): void
    {
        $this->expectException(GameNotFoundException::class);

        $this->rename(1, 'Zoe', GameId::random()->value());
    }

    public function test_a_malformed_game_id_is_not_found_rather_than_a_crash(): void
    {
        $this->expectException(GameNotFoundException::class);

        $this->rename(1, 'Zoe', 'not-a-uuid');
    }

    public function test_an_empty_seat_is_rejected(): void
    {
        $this->expectException(SeatNotFoundException::class);

        $this->rename(9, 'Zoe');
    }

    public function test_a_name_someone_else_has_is_rejected(): void
    {
        $this->expectException(NicknameTakenException::class);

        $this->rename(2, 'Ana');
    }

    public function test_a_rejected_rename_publishes_nothing(): void
    {
        // Broadcasting a state that did not happen leaves the televisions telling a different game.
        try {
            $this->rename(2, 'Ana');
        } catch (NicknameTakenException) {
            // expected
        }

        $this->assertSame([], $this->publisher->published);
    }

    public function test_the_state_can_be_read_back_by_id(): void
    {
        $snapshot = new GetGameStateHandler($this->games)
            ->handle(new GetGameStateQuery($this->gameId));

        $this->assertSame($this->gameId, $snapshot->toArray()['game_id']);
    }

    public function test_reading_an_unknown_game_is_not_found(): void
    {
        $this->expectException(GameNotFoundException::class);

        new GetGameStateHandler($this->games)->handle(new GetGameStateQuery(GameId::random()->value()));
    }

    public function test_a_television_can_find_the_game_by_its_typed_code(): void
    {
        $snapshot = new ResolveJoinCodeHandler($this->games)
            ->handle(new ResolveJoinCodeQuery(strtolower($this->joinCode)));

        $this->assertSame($this->gameId, $snapshot->toArray()['game_id']);
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        $this->expectException(GameNotFoundException::class);

        new ResolveJoinCodeHandler($this->games)->handle(new ResolveJoinCodeQuery('ZZZZZZ'));
    }

    public function test_a_malformed_code_is_not_found_rather_than_a_crash(): void
    {
        $this->expectException(GameNotFoundException::class);

        new ResolveJoinCodeHandler($this->games)->handle(new ResolveJoinCodeQuery('!!!'));
    }
}
