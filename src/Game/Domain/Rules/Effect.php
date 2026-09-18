<?php

declare(strict_types=1);

namespace Src\Game\Domain\Rules;

use InvalidArgumentException;
use JsonSerializable;
use Src\Game\Domain\ValueObjects\SeatNumber;

/**
 * One declarative thing a rule asks the table to do. The UI paints it knowing no
 * rule, and it is persisted as jsonb in `game_moves.payload`.
 *
 * **The `seat` of an effect is its recipient, never its author.** Who drew the
 * tile is the move's `actor_seat`; the `seat` of a challenge is whoever has to
 * answer it, which in `main` is the trident's seat for a face of three (TR-44)
 * and the drawer's for the other six (TR-45). A null seat means the whole table.
 * That single exception is the reason this class carries a target at all.
 *
 * `announce` and `challenge` do not merge, and must not: `announce` indexes copy
 * the application ships — trusted, translatable, parameterised — and `challenge`
 * indexes copy the players wrote — untrusted, bounded, plain text. One text
 * effect would run both through the same renderer.
 *
 * A challenge carries the key and never the text (TR-42). The frozen room
 * configuration travels in the same snapshot, so the key always resolves from
 * the same bytes, and the move history does not fill up with copies of one
 * phrase.
 *
 * There is no turn-order effect: the cursor moves only through
 * `Outcome::overrideNextSeat()`, and a second source of truth about who plays
 * next is a second source of truth that can disagree.
 *
 * An `announce` with no parameters serialises its `params` as `[]`, which is how
 * PHP encodes an empty map.
 */
final class Effect implements JsonSerializable
{
    /** Copy the application ships is addressed by a dotted key of its own space. */
    private const MESSAGE_KEY_PATTERN = '/\A[a-z0-9_]+(\.[a-z0-9_]+)*\z/';

    /** A role is an opaque machine token, never a phrase (TR-29). */
    private const ROLE_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /**
     * @param  array<string, string|int|float|bool>  $params
     */
    private function __construct(
        private readonly string $kind,
        private readonly ?SeatNumber $seat,
        private readonly ?string $role,
        private readonly ?string $configKey,
        private readonly ?string $messageKey,
        private readonly array $params,
    ) {}

    /**
     * @param  array<string, string|int|float|bool>  $params
     */
    public static function announce(string $messageKey, array $params = []): self
    {
        if (preg_match(self::MESSAGE_KEY_PATTERN, $messageKey) !== 1) {
            throw new InvalidArgumentException("'{$messageKey}' is not a message key.");
        }

        foreach ($params as $name => $value) {
            if (! is_string($name) || $name === '' || ! is_scalar($value)) {
                throw new InvalidArgumentException('An announcement parameter is a named scalar.');
            }
        }

        return new self(EffectKind::ANNOUNCE, null, null, null, $messageKey, $params);
    }

    public static function assignRole(SeatNumber $seat, string $role): self
    {
        if (preg_match(self::ROLE_PATTERN, $role) !== 1) {
            throw new InvalidArgumentException("'{$role}' is not a role.");
        }

        return new self(EffectKind::ASSIGN_ROLE, $seat, $role, null, null, []);
    }

    /** A null target addresses the whole table, rather than one effect per seat. */
    public static function challenge(?SeatNumber $target, string $configKey): self
    {
        if (! RoomConfig::isValidKey($configKey)) {
            throw new InvalidArgumentException("'{$configKey}' is not a room configuration key.");
        }

        return new self(EffectKind::CHALLENGE, $target, null, $configKey, null, []);
    }

    /**
     * Reconstruction from the jsonb payload of a move.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $kind = $data['kind'] ?? null;

        return match ($kind) {
            EffectKind::ANNOUNCE => self::announce(
                self::readString($data, 'message_key'),
                is_array($data['params'] ?? null) ? $data['params'] : [],
            ),
            EffectKind::ASSIGN_ROLE => self::assignRole(
                SeatNumber::fromInt(self::readInt($data, 'seat')),
                self::readString($data, 'role'),
            ),
            EffectKind::CHALLENGE => self::challenge(
                ($data['seat'] ?? null) === null ? null : SeatNumber::fromInt(self::readInt($data, 'seat')),
                self::readString($data, 'config_key'),
            ),
            default => throw new InvalidArgumentException('That payload is not an effect.'),
        };
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** The recipient, never the author. */
    public function seat(): ?SeatNumber
    {
        return $this->seat;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function configKey(): ?string
    {
        return $this->configKey;
    }

    public function messageKey(): ?string
    {
        return $this->messageKey;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * The bytes two clients and a jsonb column agree on: `kind` first, then the
     * keys that kind carries, and no others.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            EffectKind::ANNOUNCE => [
                'kind' => $this->kind,
                'message_key' => $this->messageKey,
                'params' => $this->params,
            ],
            EffectKind::ASSIGN_ROLE => [
                'kind' => $this->kind,
                'seat' => $this->seat?->value(),
                'role' => $this->role,
            ],
            default => [
                'kind' => $this->kind,
                'seat' => $this->seat?->value(),
                'config_key' => $this->configKey,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function readString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("An effect payload needs a string '{$key}'.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function readInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("An effect payload needs an integer '{$key}'.");
        }

        return $value;
    }
}
