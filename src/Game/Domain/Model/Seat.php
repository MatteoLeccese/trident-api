<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatId;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * A place at the table. **A player IS a seat**: there is no `Player` nor
 * `player_id` anywhere in the system.
 *
 * `roles` is a list of opaque strings that the RuleSet assigns ("trident" is just
 * one of them). The game framework does not know their meaning.
 *
 * A seat carries two things that the snapshot never shows: its `SeatId`, which is
 * the identity of its row and is minted here rather than by the repository, and
 * its `private_state`, which is whatever a RuleSet hides from the rest of the
 * table. Both survive a rename and a round trip through storage; neither appears
 * in `toArray()`.
 */
final class Seat
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $privateState
     */
    private function __construct(
        private readonly SeatId $id,
        private readonly SeatNumber $number,
        private readonly Nickname $nickname,
        private readonly array $roles,
        private readonly array $privateState,
    ) {}

    /**
     * A brand new seat: the domain assigns its identity before anything is saved.
     *
     * @param  list<string>  $roles
     */
    public static function of(SeatNumber $number, Nickname $nickname, array $roles = []): self
    {
        return new self(SeatId::random(), $number, $nickname, $roles, []);
    }

    /**
     * Reconstruction from persistence. The repository uses it; nothing else.
     *
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $privateState
     */
    public static function reconstitute(
        SeatId $id,
        SeatNumber $number,
        Nickname $nickname,
        array $roles = [],
        array $privateState = [],
    ): self {
        return new self($id, $number, $nickname, $roles, $privateState);
    }

    public function id(): SeatId
    {
        return $this->id;
    }

    public function number(): SeatNumber
    {
        return $this->number;
    }

    public function nickname(): Nickname
    {
        return $this->nickname;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return array<string, mixed>
     */
    public function privateState(): array
    {
        return $this->privateState;
    }

    /**
     * A rename changes the name and nothing else: the same seat keeps its identity
     * and its private state, so persisting it never rotates its primary key.
     */
    public function renamedTo(Nickname $nickname): self
    {
        return new self($this->id, $this->number, $nickname, $this->roles, $this->privateState);
    }

    /**
     * The same seat under a new number, which is what a lobby reorder writes.
     * Its identity, its name, its roles and its private state are untouched, so
     * renumbering the table never rotates a primary key.
     */
    public function renumberedTo(SeatNumber $number): self
    {
        return new self($this->id, $number, $this->nickname, $this->roles, $this->privateState);
    }

    /**
     * The same seat carrying one more role. Idempotent: a role it already holds
     * leaves it unchanged, so an effect applied twice assigns once (TR-28).
     *
     * The role is an opaque string here: what `trident` means is known to the
     * ruleset that emitted it and to nothing else (TR-29).
     */
    public function withRole(string $role): self
    {
        if (in_array($role, $this->roles, true)) {
            return $this;
        }

        return new self($this->id, $this->number, $this->nickname, [...$this->roles, $role], $this->privateState);
    }

    /**
     * The seat as the snapshot carries it: exactly these three keys, in this order.
     * Neither the identity nor the private state belongs here.
     *
     * @return array{seat: int, nickname: string, roles: list<string>}
     */
    public function toArray(): array
    {
        return [
            'seat' => $this->number->value(),
            'nickname' => $this->nickname->value(),
            'roles' => $this->roles,
        ];
    }
}
