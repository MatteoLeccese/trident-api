<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http;

/**
 * Translates an HTTP status into a stable machine code and a message for a person.
 *
 * It exists so that error handling is **structural and not enumerated**: any
 * `HttpException` Laravel produces — a 405, tomorrow's `abort(409)`, a maintenance
 * 503 — comes out with the correct envelope without anyone having to remember to
 * add a callback.
 *
 * A final class of constants, not a PHP enum: `enum-persistence` convention.
 */
final class HttpErrorCode
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        406 => 'not_acceptable',
        409 => 'conflict',
        410 => 'gone',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        419 => 'session_expired',
        422 => 'validation_error',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    private const MESSAGES = [
        400 => 'That request is not valid.',
        401 => 'Authentication required.',
        403 => 'You do not have permission to do that.',
        404 => 'We could not find what you asked for.',
        405 => 'That method is not allowed on this route.',
        406 => 'We cannot answer in the format you asked for.',
        409 => 'That clashes with the current state.',
        410 => 'That no longer exists.',
        413 => 'That request is too large.',
        415 => 'That content format is not supported.',
        419 => 'The session has expired.',
        422 => 'The data you sent is not valid.',
        429 => 'Too many requests. Try again in a moment.',
        503 => 'The service is not available right now.',
    ];

    public static function forStatus(int $status): string
    {
        if (isset(self::CODES[$status])) {
            return self::CODES[$status];
        }

        return $status >= 500 ? 'internal_error' : 'request_error';
    }

    /**
     * A fixed message per status. `$e->getMessage()` is never returned: an
     * `abort(409, '...')` carries developer text, and a `QueryException` carries the SQL.
     */
    public static function messageForStatus(int $status): string
    {
        if (isset(self::MESSAGES[$status])) {
            return self::MESSAGES[$status];
        }

        return $status >= 500
            ? 'Something went wrong.'
            : 'We could not process that request.';
    }
}
