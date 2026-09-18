<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Application;

use PHPUnit\Framework\TestCase;
use Src\Game\Application\Command\PlayAgain\PlayAgainCommand;
use Src\Game\Application\Command\PlayAgain\PlayAgainHandler;
use Src\Game\Application\Service\GameRules;
use Src\Game\Application\Service\JoinCodeMint;
use Src\Game\Domain\Exceptions\GameNotFoundException;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleSetResolver;
use Src\Game\Domain\Rules\Trident\TridentRuleSet;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Doubles\InMemoryGameRepository;
use Tests\Doubles\SpyGameStatePublisher;
use Tests\Support\GameProjectorFactory;

/**
 * A rematch is the same table and a new game, and the two halves of that
 * sentence are what this class asserts: what carries over, and what does not.
 *
 * The role is the interesting half. It is assigned by an effect of the ruleset,
 * no rule looks at a previous game (TR-56) and nothing survives one (TR-54,
 * TR-55), so a trident that carried across would be the only role in the system
 * the framework invented rather than a rule.
 */
final class PlayAgainHandlerTest extends TestCase
{
    /** A literal shuffle seed: a rematch tested on chance proves nothing. */
    private const SEED = 'ZbVQ8vUCcVNJNCYLbhwSEhz1vmKpSiIk0WBlGzHU7Ss';

    private InMemoryGameRepository $games;

    private SpyGameStatePublisher $publisher;

    private Game $previous;

    protected function setUp(): void
    {
        $this->games = new InMemoryGameRepository;
        $this->publisher = new SpyGameStatePublisher;

        $this->previous = Game::open(
            GameId::fromString('0f8fad5b-d9cb-469f-a165-70867728950e'),
            JoinCode::fromString('K7QP3M'),
            ControllerToken::generate(),
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-17 20:00:00'),
            Seed::fromString(self::SEED),
        );

        $this->games->save($this->previous);
        $this->publisher->published = [];
    }

    private function playAgain(?string $gameId = null): mixed
    {
        return new PlayAgainHandler(
            $this->games,
            $this->publisher,
            FrozenClock::at('2026-09-17 21:00:00'),
            GameProjectorFactory::make(),
            new JoinCodeMint($this->games),
            new GameRules(new RuleSetResolver([new TridentRuleSet]), TridentRuleSet::ID),
        )->handle(new PlayAgainCommand($gameId ?? $this->previous->id()->value()));
    }

    /**
     * Plays the previous game's first stage out, which is what puts a role on a
     * seat. The stage ends on one specific draw, so the loop stops when the
     * ruleset moves the game on rather than counting anything itself.
     */
    private function electARole(): void
    {
        $rules = new TridentRuleSet;
        $clock = FrozenClock::at('2026-09-17 20:10:00');

        $this->previous->start($rules, $clock);

        for ($position = 1; $position <= 49; $position++) {
            if ($this->previous->stage()?->value() !== TridentRuleSet::STAGE_ELECTION) {
                break;
            }

            $this->previous->drawTile(PoolPosition::fromInt($position), $rules, $clock);
        }

        $this->games->save($this->previous);
    }

    public function test_it_opens_a_game_of_its_own(): void
    {
        $next = $this->playAgain()->snapshot->toArray();
        $before = GameProjectorFactory::make()->project($this->previous)->toArray();

        $this->assertNotSame($before['game_id'], $next['game_id']);
        $this->assertNotSame($before['join_code'], $next['join_code']);
        $this->assertSame(GameStatus::LOBBY, $next['status']);
        $this->assertSame(1, $next['version']);
    }

    public function test_it_carries_the_seats_and_their_order(): void
    {
        $seats = $this->playAgain()->snapshot->toArray()['seats'];

        $this->assertSame(['Ana', 'Bea', 'Caro'], array_column($seats, 'nickname'));
        $this->assertSame([1, 2, 3], array_column($seats, 'seat'));
    }

    public function test_it_carries_the_order_the_table_dragged_itself_into(): void
    {
        $this->previous->reorderSeats(
            array_map(SeatNumber::fromInt(...), [3, 1, 2]),
            FrozenClock::at('2026-09-17 20:05:00'),
        );
        $this->games->save($this->previous);

        $seats = $this->playAgain()->snapshot->toArray()['seats'];

        $this->assertSame(['Caro', 'Ana', 'Bea'], array_column($seats, 'nickname'));
    }

    public function test_it_carries_the_table_settings(): void
    {
        $key = TridentRuleSet::challengeKey(0);

        $this->previous->configureRoom(
            RoomConfig::fromArray([$key => 'Ana goes first']),
            FrozenClock::at('2026-09-17 20:05:00'),
        );
        $this->games->save($this->previous);

        $carried = $this->playAgain()->snapshot->toArray()['room_config'];

        $this->assertSame(['Ana goes first'], [((array) $carried)[$key] ?? null]);
    }

    public function test_a_table_that_configured_nothing_opens_at_version_one(): void
    {
        // Carrying an empty configuration is not a write: no version, no entry in
        // the log, nothing broadcast twice.
        $this->assertSame(1, $this->playAgain()->snapshot->toArray()['version']);
    }

    public function test_no_role_carries_over(): void
    {
        $this->electARole();

        $before = GameProjectorFactory::make()->project($this->previous)->toArray()['seats'];
        $roles = array_merge(...array_column($before, 'roles'));

        $this->assertSame([TridentRuleSet::ROLE_TRIDENT], $roles, 'The previous table elected somebody.');

        $seats = $this->playAgain()->snapshot->toArray()['seats'];

        $this->assertSame([[], [], []], array_column($seats, 'roles'));
    }

    public function test_the_new_table_starts_with_no_board_and_no_ruleset(): void
    {
        $this->electARole();

        $next = $this->playAgain()->snapshot->toArray();

        $this->assertNull($next['stage']);
        $this->assertNull($next['current_seat']);
        $this->assertSame([], $next['pool']);
    }

    public function test_it_hands_back_a_token_of_its_own(): void
    {
        $created = $this->playAgain();

        $this->assertFalse(
            $this->previous->isControlledBy($created->controllerToken),
            'The previous game does not answer to the new token, and the cookie rotates.',
        );
    }

    public function test_it_closes_the_table_it_came_from(): void
    {
        // The reply rotates the controller cookie
        // (documentation/conventions/credential-model.md), so the previous game
        // has no writer from here on. Ending it is the only correct close: a
        // game nobody can write to, still holding a join code, is not a game.
        $this->playAgain();

        $this->assertSame(GameStatus::ABANDONED, $this->previous->status());
    }

    public function test_it_closes_a_table_that_is_mid_play(): void
    {
        $this->electARole();

        $this->playAgain();

        $this->assertSame(GameStatus::ABANDONED, $this->previous->status());
    }

    public function test_it_keeps_the_history_of_the_table_it_closed(): void
    {
        // Closing is not emptying: the append-only log carries the monotonic
        // sequence and the idempotency ledger, and a rematch that cleared it
        // would let a spent intention apply a second time.
        $this->electARole();
        $sequence = $this->previous->lastSequence();

        $this->playAgain();

        $this->assertSame($sequence + 1, $this->previous->lastSequence());
    }

    public function test_it_carries_only_the_settings_the_table_wrote(): void
    {
        // `start()` resolves the stored map through the spec, so a game in play
        // holds every declared key, its defaults included. Copying that across
        // would record today's placeholder texts (TR-51) as a choice the next
        // table made, and a chain of rematches would keep them for ever.
        $this->electARole();

        $next = $this->playAgain()->snapshot->toArray();

        $this->assertSame([], (array) $next['room_config']);
        $this->assertSame(1, $next['version']);
    }

    public function test_it_still_carries_what_a_table_in_play_did_write(): void
    {
        $key = TridentRuleSet::challengeKey(0);

        $this->previous->configureRoom(
            RoomConfig::fromArray([$key => 'Ana goes first']),
            FrozenClock::at('2026-09-17 20:05:00'),
        );
        $this->electARole();

        $carried = (array) $this->playAgain()->snapshot->toArray()['room_config'];

        $this->assertSame([$key], array_keys($carried));
        $this->assertSame('Ana goes first', $carried[$key]);
    }

    public function test_it_publishes_the_ending_of_the_table_it_closed(): void
    {
        // The television watching that game is told it is over, instead of
        // holding the last frame of a game nobody can move again.
        $created = $this->playAgain();
        $published = array_map(
            static fn (mixed $snapshot): array => $snapshot->toArray(),
            $this->publisher->published,
        );

        $this->assertCount(2, $published);
        $this->assertSame($this->previous->id()->value(), $published[0]['game_id']);
        $this->assertSame(GameStatus::ABANDONED, $published[0]['status']);
        $this->assertSame($created->snapshot->toArray()['game_id'], $published[1]['game_id']);
    }

    public function test_it_publishes_the_new_game_so_a_television_can_follow_it(): void
    {
        $created = $this->playAgain();
        $last = end($this->publisher->published);

        $this->assertNotFalse($last);
        $this->assertSame($created->snapshot->toArray()['game_id'], $last->toArray()['game_id']);
    }

    public function test_an_unknown_game_has_no_rematch(): void
    {
        $this->expectException(GameNotFoundException::class);

        $this->playAgain(GameId::random()->value());
    }

    public function test_a_malformed_game_id_is_not_found_rather_than_a_crash(): void
    {
        $this->expectException(GameNotFoundException::class);

        $this->playAgain('not-a-uuid');
    }
}
