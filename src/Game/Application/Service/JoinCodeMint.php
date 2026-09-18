<?php

declare(strict_types=1);

namespace Src\Game\Application\Service;

use RuntimeException;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\JoinCode;

/**
 * A code no live game is holding, for the two routes that open one.
 *
 * It lives in one class because both of them need it and the retry policy is a
 * single decision: a code is released when its game ends, so a table that opens
 * a rematch takes a code from the same pool the first game drew from.
 */
final class JoinCodeMint
{
    private const ATTEMPTS = 8;

    public function __construct(private readonly GameRepository $games) {}

    public function mint(): JoinCode
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $code = JoinCode::generate();

            if ($this->games->findByJoinCode($code) === null) {
                return $code;
            }
        }

        // 32^6 combinations and codes are released when a game ends: reaching here
        // means something is very wrong, not that we were unlucky.
        throw new RuntimeException('Could not find a free game code.');
    }
}
