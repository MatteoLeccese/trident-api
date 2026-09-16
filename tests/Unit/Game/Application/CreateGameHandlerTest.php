<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Application;

use PHPUnit\Framework\TestCase;
use Src\Game\Application\Command\CreateGame\CreateGameCommand;
use Src\Game\Application\Command\CreateGame\CreateGameHandler;
use Src\Game\Domain\Exceptions\NicknameTakenException;
use Src\Game\Domain\Exceptions\RosterSizeException;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Doubles\InMemoryGameRepository;
use Tests\Doubles\SpyGameStatePublisher;

final class CreateGameHandlerTest extends TestCase
{
    private InMemoryGameRepository $games;

    private SpyGameStatePublisher $publisher;

    protected function setUp(): void
    {
        $this->games = new InMemoryGameRepository;
        $this->publisher = new SpyGameStatePublisher;
    }

    /**
     * @param  list<string>  $nicknames
     */
    private function create(array $nicknames = ['Ana', 'Bea', 'Caro']): mixed
    {
        $handler = new CreateGameHandler(
            $this->games,
            $this->publisher,
            FrozenClock::at('2026-09-16 20:00:00'),
        );

        return $handler->handle(new CreateGameCommand($nicknames));
    }

    public function test_it_opens_a_game_in_the_lobby(): void
    {
        $created = $this->create();

        $this->assertSame(GameStatus::LOBBY, $created->snapshot->toArray()['status']);
        $this->assertSame(1, $created->snapshot->toArray()['version']);
    }

    public function test_it_seats_everyone_in_the_order_they_were_typed(): void
    {
        $seats = $this->create(['Ana', 'Bea', 'Caro', 'Dani'])->snapshot->toArray()['seats'];

        $this->assertSame(['Ana', 'Bea', 'Caro', 'Dani'], array_column($seats, 'nickname'));
        $this->assertSame([1, 2, 3, 4], array_column($seats, 'seat'));
    }

    public function test_it_persists_the_game(): void
    {
        $created = $this->create();

        $this->assertNotNull($this->games->find(GameId::fromString($created->snapshot->toArray()['game_id'])));
    }

    public function test_it_hands_back_the_controller_token_exactly_once(): void
    {
        // It is the only time the plaintext token exists outside the cookie.
        $created = $this->create();

        $this->assertNotSame('', $created->controllerToken->value());
        $this->assertStringNotContainsString(
            $created->controllerToken->value(),
            json_encode($created->snapshot->toArray()) ?: '',
        );
    }

    public function test_the_game_recognises_that_token_and_no_other(): void
    {
        $created = $this->create();
        $game = $this->games->find(GameId::fromString($created->snapshot->toArray()['game_id']));

        $this->assertNotNull($game);
        $this->assertTrue($game->isControlledBy($created->controllerToken));
    }

    public function test_it_gives_the_game_a_join_code_the_television_can_type(): void
    {
        $code = $this->create()->snapshot->toArray()['join_code'];

        $this->assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{6}\z/', $code);
    }

    public function test_join_codes_do_not_collide(): void
    {
        $codes = [];

        for ($i = 0; $i < 25; $i++) {
            $codes[] = $this->create()->snapshot->toArray()['join_code'];
        }

        $this->assertCount(25, array_unique($codes));
    }

    public function test_it_publishes_the_opening_state(): void
    {
        $this->create();

        $this->assertCount(1, $this->publisher->published);
    }

    public function test_it_refuses_a_table_that_is_too_small(): void
    {
        $this->expectException(RosterSizeException::class);

        $this->create(['Ana', 'Bea']);
    }

    public function test_it_refuses_repeated_names(): void
    {
        $this->expectException(NicknameTakenException::class);

        $this->create(['Ana', 'Bea', 'ana']);
    }

    public function test_a_rejected_creation_leaves_nothing_behind(): void
    {
        try {
            $this->create(['Ana', 'Bea']);
        } catch (RosterSizeException) {
            // expected
        }

        $this->assertSame([], $this->publisher->published);
    }
}
