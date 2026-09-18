<?php

declare(strict_types=1);

namespace Src\Game\Domain\Repository;

use Src\Game\Domain\Model\GameTally;

/**
 * Outbound port for the one question asked of the whole table rather than of one
 * game: how many did the room finish.
 *
 * It is deliberately **not** a method on `GameRepository`. That port loads and
 * saves one aggregate, and a count over every row that has ever existed is not
 * an aggregate operation: putting it there would invite the next reporting
 * question to arrive as a second one, and a repository that answers reports
 * stops being the place a single game is read from.
 *
 * It answers a value object and never a row, an array of rows or a query
 * builder, so no caller can reach past it into the schema.
 */
interface GameTallyReader
{
    public function tally(): GameTally;
}
