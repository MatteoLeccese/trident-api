<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Row of `games`. **It is not the aggregate**: it is its database shape.
 * The domain lives in `Src\Game\Domain\Model\Game` and does not know this exists.
 */
final class GameModel extends Model
{
    protected $table = 'games';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * The jsonb columns are cast, unlike the seats', because a game row is
     * written through Eloquent and a seat row through a bulk insert, where casts
     * do not apply. What the repository hands over still decides the JSON type:
     * an empty `array` encodes as `[]`, so a column that holds a map is given a
     * `stdClass` and comes back out as an array.
     */
    protected $casts = [
        'version' => 'integer',
        'current_seat' => 'integer',
        'last_activity_at' => 'immutable_datetime',
        'pool' => 'array',
        'rule_state' => 'array',
        'room_config' => 'array',
        'stage_visits' => 'array',
        'pending_choice' => 'array',
    ];

    public function seats(): HasMany
    {
        return $this->hasMany(GameSeatModel::class, 'game_id')->orderBy('seat_number');
    }

    public function moves(): HasMany
    {
        return $this->hasMany(GameMoveModel::class, 'game_id')->orderBy('seq');
    }
}
