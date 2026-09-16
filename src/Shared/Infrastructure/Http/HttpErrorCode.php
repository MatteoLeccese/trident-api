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
        400 => 'La petición no es válida.',
        401 => 'Autenticación requerida.',
        403 => 'No tienes permiso para hacer eso.',
        404 => 'No se encontró el recurso solicitado.',
        405 => 'Ese método no está permitido en esta ruta.',
        406 => 'No podemos responder en el formato pedido.',
        409 => 'La operación entra en conflicto con el estado actual.',
        410 => 'Eso ya no existe.',
        413 => 'La petición es demasiado grande.',
        415 => 'Formato de contenido no soportado.',
        419 => 'La sesión ha caducado.',
        422 => 'Los datos enviados no son válidos.',
        429 => 'Demasiadas peticiones. Prueba en un momento.',
        503 => 'El servicio no está disponible ahora mismo.',
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
            ? 'Ha ocurrido un error inesperado.'
            : 'No hemos podido procesar la petición.';
    }
}
