<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http;

use Illuminate\Http\JsonResponse;

/**
 * The API's single envelope: { status, message, error, data } and, only when it is
 * needed, `meta`. Always the same key order — the contract with the frontend is a
 * shape, not a set of keys.
 *
 * Eloquent API Resources are not used: responses are built from the domain DTOs'
 * `->toArray()`.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK'): JsonResponse
    {
        return self::envelope(200, $message, null, $data);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::envelope(201, $message, null, $data);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function successWithMeta(mixed $data, array $meta, string $message = 'OK'): JsonResponse
    {
        return self::envelope(200, $message, null, $data, $meta);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function error(
        string $errorCode,
        string $message,
        int $status = 422,
        ?array $data = null,
    ): JsonResponse {
        return self::envelope($status, $message, $errorCode, $data);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private static function envelope(
        int $status,
        string $message,
        ?string $error,
        mixed $data,
        ?array $meta = null,
    ): JsonResponse {
        $body = [
            'status' => $status,
            'message' => $message,
            'error' => $error,
            'data' => $data,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status);
    }
}
