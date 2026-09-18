<?php

declare(strict_types=1);

namespace Src\Game\Application\Query\TallyGames;

use Src\Shared\Domain\Bus\Query;

/**
 * How many games began against how many reached their end.
 *
 * It takes no argument: there is one table, one product and one question.
 */
final class TallyGamesQuery implements Query {}
