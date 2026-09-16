<?php

declare(strict_types=1);

namespace Src\Game\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class GameMoveModel extends Model
{
    protected $table = 'game_moves';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'seq' => 'integer',
        'actor_seat' => 'integer',
        'payload' => 'array',
    ];
}
