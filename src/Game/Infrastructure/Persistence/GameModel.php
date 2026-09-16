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

    protected $casts = [
        'version' => 'integer',
        'last_activity_at' => 'immutable_datetime',
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
