<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Rules\GameContext;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\RuleState;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\Draw;
use Src\Game\Domain\ValueObjects\DrawLog;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\PoolPosition;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Game\Domain\ValueObjects\Tile;

/**
 * The DTO a rule reads when nobody is drawing. Purpose-built: neither the
 * snapshot, which is the one class whose job is to be safe to broadcast, nor the
 * aggregate, which would make the seam's real surface the whole model.
 */
final class GameContextTest extends TestCase
{
    private function roster(): SeatRoster
    {
        return SeatRoster::fromNicknames([
            Nickname::fromString('Ana'),
            Nickname::fromString('Bo'),
            Nickname::fromString('Cy'),
        ]);
    }

    private function context(?SeatNumber $currentSeat, int $turnNumber = 0): GameContext
    {
        return GameContext::of(
            StageId::fromString('election'),
            $this->roster(),
            $currentSeat,
            DrawLog::of([Draw::of('election', SeatNumber::first(), PoolPosition::first(), Tile::fromString('21'))]),
            $turnNumber,
            RuleState::initial(1),
            RoomConfig::fromArray(['drawn_tiles.election' => 'keep']),
        );
    }

    public function test_it_hands_a_rule_everything_it_was_given(): void
    {
        $context = $this->context(SeatNumber::fromInt(2), 7);

        $this->assertSame('election', $context->stage()->value());
        $this->assertSame(3, $context->seats()->count());
        $this->assertSame(2, $context->currentSeat()?->value());
        $this->assertSame(7, $context->turnNumber());
        $this->assertSame(1, $context->state()->version());
        $this->assertSame('keep', $context->roomConfig()->get('drawn_tiles.election'));
    }

    public function test_the_current_seat_is_nullable_because_a_game_starts_without_one(): void
    {
        // onGameStarted runs before a cursor exists: a non-nullable type would
        // force inventing a seat zero for the one moment when there is none.
        $this->assertNull($this->context(null)->currentSeat());
    }

    public function test_it_carries_the_history_even_when_nobody_is_drawing(): void
    {
        // deck() and visibility() are the two places a rule may depend on what has
        // already come up.
        $this->assertSame(['21'], array_map(
            static fn (Tile $tile): string => $tile->value(),
            $this->context(null)->priorDraws()->tiles(),
        ));
    }

    public function test_a_role_is_read_from_the_seats_and_never_from_a_field_of_its_own(): void
    {
        // "Trident" is a role in the seats (TR-29). A dedicated field here would
        // be a rule baked into the shape of the seam.
        $fields = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(GameContext::class))->getProperties(),
        );

        $this->assertSame(
            ['stage', 'seats', 'currentSeat', 'priorDraws', 'turnNumber', 'state', 'roomConfig'],
            $fields,
        );
        $this->assertNotContains('tridentSeat', $fields);
    }

    public function test_it_is_not_a_projection(): void
    {
        // It is built for rules and never emitted, so it has no wire shape: a
        // rule that needs more history changes this DTO and never the snapshot.
        $this->assertFalse(method_exists(GameContext::class, 'toArray'));
        $this->assertFalse(method_exists(GameContext::class, 'jsonSerialize'));
    }

    public function test_a_turn_number_below_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->context(null, -1);
    }
}
