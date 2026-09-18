<?php

declare(strict_types=1);

namespace Src\Game\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Src\Game\Domain\Rules\RoomConfig;
use Src\Game\Domain\Rules\StageId;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\GameStatus;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\SeatNumber;
use Src\Shared\Domain\ValueObjects\Version;
use stdClass;

/**
 * **The one and only state shape of the system.**
 *
 * It is what `GET /api/v1/games/{id}` returns and what `broadcastWith()` sends.
 * A test makes sure the two paths produce identical bytes: the old controller
 * silently dropped fields that its own service returned, and that is exactly the
 * failure this prevents.
 *
 * A full payload and not an incremental one, on purpose: this way a lost event
 * heals itself, and late joining, reconnection and the sleeping television are
 * all the same trivial case.
 *
 * **One projection for everybody** (TR-06). The board is hidden by stage and
 * never by viewer: the pool arrives here already projected through
 * `RuleSet::visibility()`, so the phone that is being passed around the table
 * receives the same bytes as the television and cannot read the board in its
 * devtools.
 *
 * It carries the **effects of the write it came from** beside the state that
 * write produced. The seam's output is declarative and closed
 * (documentation/conventions/rule-set-seam.md) and this is the only path it
 * takes to either client: a challenge names the seat it is addressed to, so no
 * client has to work out that the face of three belongs to the trident (TR-44).
 * Both delivery paths build it from `Effect::toArray()`, so they agree byte for
 * byte.
 *
 * **It never contains a credential**: not the token, not its hash, not the
 * handover code, and above all **not `games.shuffle_seed`** (TR-09) — whoever
 * holds the seed recomputes every face-down position of both pools and ends the
 * game in silence, with nothing in any log to show for it. A test enforces it.
 */
final class GameSnapshot
{
    /**
     * @param  list<array{position: int, tile: string|null, taken: bool, seat: int|null, on_board: bool}>  $pool
     * @param  array{position: int, tile: string, seat: int}|null  $lastDraw
     * @param  list<array<string, mixed>>  $effects
     */
    private function __construct(
        private readonly GameId $id,
        private readonly JoinCode $joinCode,
        private readonly string $status,
        private readonly SeatRoster $seats,
        private readonly Version $version,
        private readonly DateTimeImmutable $lastActivityAt,
        private readonly ?StageId $stage,
        private readonly ?SeatNumber $currentSeat,
        private readonly array $pool,
        private readonly ?array $lastDraw,
        private readonly RoomConfig $roomConfig,
        private readonly int $tvIdleNoticeMinutes,
        private readonly array $effects,
    ) {}

    /**
     * @param  list<array{position: int, tile: string|null, taken: bool, seat: int|null, on_board: bool}>  $pool
     * @param  array{position: int, tile: string, seat: int}|null  $lastDraw
     * @param  list<array<string, mixed>>  $effects
     */
    public static function of(
        GameId $id,
        JoinCode $joinCode,
        string $status,
        SeatRoster $seats,
        Version $version,
        DateTimeImmutable $lastActivityAt,
        ?StageId $stage,
        ?SeatNumber $currentSeat,
        array $pool,
        RoomConfig $roomConfig,
        int $tvIdleNoticeMinutes,
        array $effects = [],
        ?array $lastDraw = null,
    ): self {
        return new self(
            $id,
            $joinCode,
            $status,
            $seats,
            $version,
            $lastActivityAt,
            $stage,
            $currentSeat,
            $pool,
            $lastDraw,
            $roomConfig,
            $tvIdleNoticeMinutes,
            $effects,
        );
    }

    /**
     * @return array{
     *     game_id: string,
     *     version: int,
     *     status: string,
     *     join_code: string|null,
     *     stage: string|null,
     *     current_seat: int|null,
     *     seats: list<array{seat: int, nickname: string, roles: list<string>}>,
     *     pool: list<array{position: int, tile: string|null, taken: bool, seat: int|null, on_board: bool}>,
     *     last_draw: array{position: int, tile: string, seat: int}|null,
     *     effects: list<array<string, mixed>>,
     *     room_config: stdClass,
     *     tv_idle_notice_minutes: int,
     *     last_activity_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'game_id' => $this->id->value(),
            'version' => $this->version->value(),
            'status' => $this->status,
            // A terminal game has released its code, so both delivery paths carry
            // null for it: the row no longer holds the code, and a live aggregate
            // that still remembers one must not project a code nobody can use.
            'join_code' => GameStatus::isTerminal($this->status)
                ? null
                : $this->joinCode->value(),
            // An opaque string the RuleSet owns: no client branches on its value,
            // and a game that has not started projects null rather than omitting
            // the key.
            'stage' => $this->stage?->value(),
            // The one seat that acts (TR-10). Null explicit, never an absent key.
            'current_seat' => $this->currentSeat?->value(),
            // Array of objects with an explicit `seat`: never a positional map,
            // which was another of the ways the old system diverged.
            'seats' => $this->seats->toArray(),
            // Same rule with an explicit `position`, and an untaken position
            // carries `tile: null` unless the stage opens the board (TR-07).
            //
            // Each position also carries the seat that took it — null while
            // nobody has — which is what lets a television show who filled the
            // board without counting a pip; and `on_board`, the flag
            // `RuleSet::boardPresence()` resolves for the stage (TR-52), which is
            // false for a taken position the board no longer draws. Both are
            // framework fields: a client branches on them and names no setting,
            // no stage and no rule.
            'pool' => $this->pool,
            // The draw that was last made, which the pool stops being a record of
            // the moment a stage ends: the draw that finishes a stage carries the
            // NEXT stage's pool in the same write (TR-04, TR-25), so the position
            // that was just turned over comes back untaken and face down in it.
            // Without this field, the one draw of the evening the game is named
            // after — the tile that makes somebody the trident (TR-24) — is the
            // one draw no client can show.
            //
            // It publishes nothing the pool would not have: a taken position
            // projects its face under both declared visibilities, so this is the
            // same byte surviving a pool it no longer belongs to. Null until a
            // tile has been turned over at all.
            'last_draw' => $this->lastDraw,
            // What the rules asked the table to do on the write this version came
            // from, declarative and closed, in the order the ruleset emitted them
            // (documentation/conventions/rule-set-seam.md). It is the seam's whole
            // output: without it a client would have to decide for itself who a
            // challenge is addressed to (TR-44), which is the one rule the seam
            // exists to keep out of a client.
            //
            // It is not a delta the client accumulates. It belongs to this version
            // the way the board does, it is read back from the log with the state,
            // and a version that changed no board — a rename — carries an empty
            // list. A frame that is lost stays lost, exactly like the tile that was
            // face up on the table while nobody was looking.
            'effects' => $this->effects,
            // A JSON object even when it holds nothing: the client types a map of
            // dotted keys, and an empty PHP array would encode as `[]`.
            //
            // Sorted by key, because the order of a map is not information and the
            // two delivery paths must agree byte for byte. `jsonb` stores an
            // object's keys in its own order — by length, then by bytes — so a
            // game read back out of a row would otherwise carry the same settings
            // in a different order from the aggregate that was just broadcast.
            'room_config' => (object) $this->sortedRoomConfig(),
            // A deployment value and not a table's choice, which is why it sits
            // outside `room_config`: minutes of silence after which the watch
            // screen says the game looks abandoned. It travels in the snapshot
            // because a television arrives by JoinCode and never saw the creation
            // response.
            'tv_idle_notice_minutes' => $this->tvIdleNoticeMinutes,
            'last_activity_at' => $this->lastActivityAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private function sortedRoomConfig(): array
    {
        $config = $this->roomConfig->toArray();

        ksort($config);

        return $config;
    }

    public function gameId(): GameId
    {
        return $this->id;
    }

    public function version(): Version
    {
        return $this->version;
    }
}
