<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Application;

use PHPUnit\Framework\TestCase;
use Src\Game\Application\Service\GameWriter;
use Src\Game\Domain\Exceptions\GameVersionConflictException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\MoveKind;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Shared\Domain\Exceptions\BusinessException;
use Src\Shared\Domain\ValueObjects\Uuid;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Doubles\InMemoryGameRepository;
use Tests\Doubles\TrailGameRepository;
use Tests\Doubles\TrailStatePublisher;
use Tests\Support\GameProjectorFactory;

/**
 * The shape of the write protocol, which no assertion on a response body can
 * make.
 *
 * Two taps that overlap are the scenario this phase is named for: one phone, one
 * table, a laggy link. If every guard reads outside the transaction that saves,
 * both taps read the same version, both pass the replay check and the version
 * check, and the loser dies on `unique(game_id, seq)` — a `500 database_error`
 * with no state in it to heal from, instead of a replay or a version conflict.
 * A single-process test cannot interleave two writers, so what is pinned here is
 * the property that makes the interleaving safe: the whole protocol is one unit
 * of work, and the broadcast waits for it to close.
 */
final class GameWriterTest extends TestCase
{
    private const GAME_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    /** A literal shuffle seed: a protocol tested on chance proves nothing. */
    private const SEED = 'ZbVQ8vUCcVNJNCYLbhwSEhz1vmKpSiIk0WBlGzHU7Ss';

    private TrailGameRepository $games;

    private GameWriter $writer;

    protected function setUp(): void
    {
        $this->games = new TrailGameRepository(new InMemoryGameRepository);

        $this->games->save(Game::open(
            GameId::fromString(self::GAME_ID),
            JoinCode::fromString('K7QP3M'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-17 20:00:00'),
            Seed::fromString(self::SEED),
        ));

        $this->writer = new GameWriter(
            $this->games,
            new TrailStatePublisher($this->games),
            GameProjectorFactory::make(),
        );

        $this->games->trail = [];
    }

    private function rename(string $nickname, ?int $expectedVersion = null, ?string $requestId = null): void
    {
        $this->writer->write(
            self::GAME_ID,
            $expectedVersion,
            $requestId,
            MoveKind::SEAT_RENAMED,
            function (Game $game) use ($nickname): void {
                $game->renameSeat(
                    SeatNumber::first(),
                    Nickname::fromString($nickname),
                    FrozenClock::at('2026-09-17 20:05:00'),
                );
            },
        );
    }

    public function test_the_read_the_guards_and_the_save_are_one_unit_of_work(): void
    {
        $this->rename('Anita', 1, Uuid::random()->value());

        $this->assertSame(
            ['begin', 'findForUpdate', 'intentionOfRequest', 'save', 'end', 'publish'],
            $this->games->trail,
        );
    }

    public function test_the_read_is_the_locking_one(): void
    {
        // `find()` reads a game and leaves it free for the next writer. The write
        // protocol may not use it: the guards it runs are only worth what the row
        // they read is held for.
        $this->rename('Anita');

        $this->assertNotContains('find', $this->games->trail);
        $this->assertContains('findForUpdate', $this->games->trail);
    }

    public function test_nothing_is_published_until_the_unit_of_work_has_closed(): void
    {
        // A television told about a version a rollback then takes away is a
        // television showing a board that does not exist.
        $this->rename('Anita');

        $trail = $this->games->trail;

        $this->assertSame(count($trail) - 1, array_search('publish', $trail, true));
        $this->assertSame('end', $trail[count($trail) - 2]);
    }

    public function test_a_refused_write_publishes_nothing_and_saves_nothing(): void
    {
        $this->rename('Anita');
        $this->games->trail = [];

        try {
            $this->rename('Bea Maria', 1);
            $this->fail('A stale write is refused.');
        } catch (GameVersionConflictException) {
            // The refusal is the point; what it left behind is what is asserted.
        }

        $this->assertSame(['begin', 'findForUpdate', 'end'], $this->games->trail);
    }

    public function test_a_write_that_changed_nothing_saves_nothing_and_publishes_nothing(): void
    {
        $this->rename('Ana');

        $this->assertSame(['begin', 'findForUpdate', 'end'], $this->games->trail);
    }

    public function test_an_intention_spent_on_another_kind_of_write_is_refused(): void
    {
        $id = Uuid::random()->value();

        $this->rename('Anita', null, $id);

        $this->expectException(BusinessException::class);

        $this->writer->write(
            self::GAME_ID,
            null,
            $id,
            MoveKind::SEATS_REORDERED,
            function (Game $game): void {
                $game->reorderSeats(
                    array_map(SeatNumber::fromInt(...), [3, 1, 2]),
                    FrozenClock::at('2026-09-17 20:06:00'),
                );
            },
        );
    }
}
