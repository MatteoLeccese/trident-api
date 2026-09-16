<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/**
 * The root of every expected business failure.
 *
 * It renders as a 4xx with a stable machine code, and it is NOT reported to the
 * log: a player playing out of turn is not an incident.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * snake_case machine code that the frontend branches on.
     * Never branch on the text of the message.
     */
    abstract public function errorCode(): string;

    public function status(): int
    {
        return 422;
    }

    /**
     * Data the client needs in order to recover (e.g. the current state in a
     * version conflict). It is not for debugging.
     *
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        return null;
    }

    protected static function assertSnakeCase(string $errorCode): string
    {
        // \z rather than $: in PCRE, $ also matches just before a trailing newline.
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $errorCode) !== 1) {
            throw new InvalidArgumentException(
                "El código de error '{$errorCode}' debe ser snake_case: minúsculas, dígitos y guiones bajos, empezando por letra.",
            );
        }

        return $errorCode;
    }
}
