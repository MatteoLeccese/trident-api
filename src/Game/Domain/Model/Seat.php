<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use Src\Game\Domain\ValueObjects\Nickname;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * A place at the table. **A player IS a seat**: there is no `Player` nor
 * `player_id` anywhere in the system.
 *
 * `roles` is a list of opaque strings that the RuleSet assigns ("trident" is just
 * one of them). The game framework does not know their meaning.
 */
final class Seat
{
    /**
     * @param  list<string>  $roles
     */
    private function __construct(
        private readonly SeatNumber $number,
        private readonly Nickname $nickname,
        private readonly array $roles,
    ) {}

    /**
     * @param  list<string>  $roles
     */
    public static function of(SeatNumber $number, Nickname $nickname, array $roles = []): self
    {
        return new self($number, $nickname, $roles);
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

    public function renamedTo(Nickname $nickname): self
    {
        return new self($this->number, $nickname, $this->roles);
    }

    /**
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
