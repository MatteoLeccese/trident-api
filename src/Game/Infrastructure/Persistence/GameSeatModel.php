<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class GameSeatModel extends Model
{
    protected $table = 'game_seats';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'seat_number' => 'integer',
        'roles' => 'array',
        'private_state' => 'array',
    ];
}
