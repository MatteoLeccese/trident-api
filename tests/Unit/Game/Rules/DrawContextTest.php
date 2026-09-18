<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\DrawContext;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Seed;
use Src\Game\Domain\ValueObjects\Tile;
use Src\Game\Domain\ValueObjects\TileDeck;
use Src\Game\Domain\ValueObjects\TilePool;

/**
 * The DTO a rule reads about one draw.
 */
final class DrawContextTest extends TestCase
{
    private function pool(): TilePool
    {
        return TilePool::fromDeck(
            TileDeck::standard(),
            Seed::fromString('0123456789abcdefghijklmnopqrstuvwxyzABCDEFG'),
            'main',
        );
    }

    private function context(int $turnNumber = 1): DrawContext
    {
        return DrawContext::of(
            SeatNumber::fromInt(2),
            PoolPosition::fromInt(7),
            Tile::fromString('33'),
            SeatRoster::fromNicknames([
                Nickname::fromString('Ana'),
                Nickname::fromString('Bo'),
                Nickname::fromString('Cy'),
            ]),
            DrawLog::empty(),
            $this->pool(),
            $turnNumber,
            StageId::fromString('main'),
            RuleState::initial(1),
            RoomConfig::empty(),
        );
    }

    public function test_it_hands_a_rule_everything_it_was_given(): void
    {
        $context = $this->context(12);

        $this->assertSame(2, $context->seat()->value());
        $this->assertSame(7, $context->position()->value());
        $this->assertSame('33', $context->tile()->value());
        $this->assertSame(3, $context->seats()->count());
        $this->assertTrue($context->priorDraws()->isEmpty());
        $this->assertSame(12, $context->turnNumber());
        $this->assertSame('main', $context->stage()->value());
        $this->assertSame(1, $context->state()->version());
        $this->assertSame([], $context->roomConfig()->toArray());
    }

    public function test_it_carries_both_the_position_touched_and_the_tile_found(): void
    {
        // The position is what the phone touched, the tile is what the server
        // found when it turned it over. A rule may decide with either.
        $context = $this->context();

        $this->assertNotSame($context->poolBefore()->at($context->position())->value(), '');
        $this->assertSame('33', $context->tile()->value());
    }

    public function test_the_pool_it_carries_is_the_one_from_before_this_draw(): void
    {
        $context = $this->context();

        $this->assertFalse($context->poolBefore()->isTaken($context->position()));
        $this->assertSame(49, $context->poolBefore()->remaining());
    }

    public function test_it_carries_no_seat_but_the_drawer(): void
    {
        // A draw is attributed to the current seat and the phone never sends one.
        $fields = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(DrawContext::class))->getProperties(),
        );

        $this->assertSame(
            ['seat', 'position', 'tile', 'seats', 'priorDraws', 'poolBefore', 'turnNumber', 'stage', 'state', 'roomConfig'],
            $fields,
        );
    }

    public function test_a_draw_happens_on_a_turn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->context(0);
    }
}
